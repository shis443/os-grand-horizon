<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Single normalization entry point for ALL inbound Channex bookings —
 * called identically whether triggered by the webhook (via the revision
 * feed) or the scheduled reconciliation cron. See PLAN_CHANNEL.md §6.
 *
 * Input is a decoded Channex "booking" object's `attributes` (the schema
 * confirmed from https://docs.channex.io/api-v.1-documentation/bookings-collection.md),
 * NOT the sparse webhook payload — the webhook only ever tells us to go
 * fetch fresh data (§2b).
 */
class Channel_normalizer {

	private $CI;

	public function __construct()
	{
		$this->CI =& get_instance();
		$this->CI->load->model('Channex_model');
		$this->CI->load->model('Room_model');
		$this->CI->load->model('Company_model');
		$this->CI->load->model('channel_reservations_model', 'channel_reservations');
		$this->CI->load->model('housekeeping_model', 'hk');
		$this->CI->load->model('channel_push_queue_model', 'push_queue');
	}

	/**
	 * @param array $booking   decoded Channex booking `attributes` object
	 * @param int   $company_id
	 * @return array {ok: bool, message: string, local_booking_id: int|null}
	 */
	public function normalize($booking, $company_id)
	{
		$channex_booking_id = $booking['id'];
		$status = $booking['status']; // 'new' | 'modified' | 'cancelled' — confirmed exact values, §2/§6
		$ota_name = isset($booking['ota_name']) ? $booking['ota_name'] : null;
		$source = $this->resolve_source_constant($ota_name);

		$existing_ids = $this->CI->Channex_model->get_related_pms_booking_ids($channex_booking_id);
		$existing_booking_id = !empty($existing_ids) ? $existing_ids[0] : null;

		if ($status === 'cancelled') {
			return $this->handle_cancelled($booking, $existing_booking_id, $company_id, $channex_booking_id);
		}

		if ($existing_booking_id) {
			return $this->handle_modified($booking, $existing_booking_id, $company_id, $channex_booking_id, $source, $ota_name);
		}

		return $this->handle_new($booking, $company_id, $channex_booking_id, $source, $ota_name);
	}

	private function handle_new($booking, $company_id, $channex_booking_id, $source, $ota_name)
	{
		$room = isset($booking['rooms'][0]) ? $booking['rooms'][0] : null;
		if (!$room) {
			return array('ok' => false, 'message' => 'Booking has no rooms[] entry', 'local_booking_id' => null);
		}

		$local_room_type_id = $this->resolve_room_type($room, $company_id);
		if (!$local_room_type_id) {
			$this->audit($company_id, 'channel_unmapped_room', null,
				"Channex booking $channex_booking_id references an unmapped room_type_id ({$room['room_type_id']}) — ignored, map it on the Channels page first.");
			return array('ok' => false, 'message' => 'Unmapped room_type_id', 'local_booking_id' => null);
		}

		$arrival = isset($room['checkin_date']) ? $room['checkin_date'] : $booking['arrival_date'];
		$departure = isset($room['checkout_date']) ? $room['checkout_date'] : $booking['departure_date'];

		$resolved_room = $this->resolve_specific_room($local_room_type_id, $arrival, $departure, $company_id);
		if (!$resolved_room) {
			$this->audit($company_id, 'channel_conflict_detected', null,
				"Channex booking $channex_booking_id ($arrival to $departure): no free room of the mapped type — every physical room is already occupied for these dates. Needs manual review.");
			return array('ok' => false, 'message' => 'No free room of mapped type — conflict', 'local_booking_id' => null);
		}

		$customer_id = $this->find_or_create_customer($booking, $company_id);

		$company = $this->CI->Company_model->get_company($company_id);
		$check_in_time = $this->normalize_time($company['default_checkin_time']);
		$check_out_time = $this->normalize_time($company['default_checkout_time']);

		$this->CI->db->insert('booking', array(
			'company_id'          => $company_id,
			'booking_customer_id' => $customer_id,
			'state'               => RESERVATION,
			'source'              => $source,
			'adult_count'         => isset($room['occupancy']['adults']) ? (int) $room['occupancy']['adults'] : 1,
			'children_count'      => isset($room['occupancy']['children']) ? (int) $room['occupancy']['children'] : 0,
		));
		$local_booking_id = $this->CI->db->insert_id();

		$this->CI->db->insert('booking_block', array(
			'booking_id'     => $local_booking_id,
			'room_id'        => $resolved_room['room_id'],
			'room_type_id'   => $local_room_type_id,
			'check_in_date'  => $arrival . ' ' . $check_in_time . ':00',
			'check_out_date' => $departure . ' ' . $check_out_time . ':00',
		));

		$this->save_channel_reservation($booking, $local_booking_id, $channex_booking_id, $ota_name, $resolved_room['room_name'], $arrival, $departure);
		$this->maybe_flag_turnover($resolved_room['room_id'], $local_booking_id, $arrival, $company_id);
		$this->audit($company_id, 'channel_booking_new', $resolved_room['room_name'],
			"New $ota_name booking ingested for {$resolved_room['room_name']}, $arrival to $departure.");
		$this->CI->push_queue->enqueue($resolved_room['room_id'], $arrival, $departure, 'channel_booking_new');

		return array('ok' => true, 'message' => 'created', 'local_booking_id' => $local_booking_id);
	}

	private function handle_modified($booking, $local_booking_id, $company_id, $channex_booking_id, $source, $ota_name)
	{
		$existing = $this->CI->db->where('booking_id', $local_booking_id)->get('booking')->row_array();

		if (!$existing) {
			// dangling ota_bookings row pointing at a booking that no longer exists locally — treat as new
			return $this->handle_new($booking, $company_id, $channex_booking_id, $source, $ota_name);
		}

		$is_ota_sourced = ((int) $existing['source']) < 0 && ((int) $existing['source']) !== (int) SOURCE_WALK_IN;

		if (!$is_ota_sourced) {
			// Never silently overwrite a manual/local booking that happens to occupy
			// the same slot — flag for a human instead (PLAN_CHANNEL.md §6 step 3).
			$this->audit($company_id, 'channel_conflict_detected', null,
				"Channex sent a modification for booking $channex_booking_id, but local booking #$local_booking_id is not channel-sourced (source={$existing['source']}). NOT overwritten — needs manual review.");
			return array('ok' => false, 'message' => 'Conflict with non-OTA local booking — not overwritten', 'local_booking_id' => $local_booking_id);
		}

		$room = isset($booking['rooms'][0]) ? $booking['rooms'][0] : null;
		$arrival = $room && isset($room['checkin_date']) ? $room['checkin_date'] : $booking['arrival_date'];
		$departure = $room && isset($room['checkout_date']) ? $room['checkout_date'] : $booking['departure_date'];

		$block = $this->CI->db->where('booking_id', $local_booking_id)->get('booking_block')->row_array();
		$room_row = $block ? $this->CI->Room_model->get_room($block['room_id']) : null;
		$room_name = $room_row ? $room_row['room_name'] : null;

		if ($block) {
			$check_in_time = substr($block['check_in_date'], 11) ?: '15:00:00';
			$check_out_time = substr($block['check_out_date'], 11) ?: '11:00:00';
			$this->CI->db->where('booking_room_history_id', $block['booking_room_history_id'])->update('booking_block', array(
				'check_in_date'  => $arrival . ' ' . $check_in_time,
				'check_out_date' => $departure . ' ' . $check_out_time,
			));
		}

		$this->CI->db->where('booking_id', $local_booking_id)->update('booking', array(
			'adult_count'    => isset($room['occupancy']['adults']) ? (int) $room['occupancy']['adults'] : $existing['adult_count'],
			'children_count' => isset($room['occupancy']['children']) ? (int) $room['occupancy']['children'] : $existing['children_count'],
		));

		$this->save_channel_reservation($booking, $local_booking_id, $channex_booking_id, $ota_name, $room_name, $arrival, $departure);
		$this->audit($company_id, 'channel_booking_modified', $room_name,
			"$ota_name booking modified: $channex_booking_id now $arrival to $departure.");

		if ($block) {
			$this->CI->push_queue->enqueue($block['room_id'], $arrival, $departure, 'channel_booking_modified');
		}

		return array('ok' => true, 'message' => 'updated', 'local_booking_id' => $local_booking_id);
	}

	private function handle_cancelled($booking, $local_booking_id, $company_id, $channex_booking_id)
	{
		if (!$local_booking_id) {
			// Cancellation for a booking we never had locally (e.g. it was unmapped on arrival) — nothing to cancel.
			return array('ok' => true, 'message' => 'nothing to cancel locally', 'local_booking_id' => null);
		}

		$block = $this->CI->db->where('booking_id', $local_booking_id)->get('booking_block')->row_array();
		$room_row = $block ? $this->CI->Room_model->get_room($block['room_id']) : null;
		$room_name = $room_row ? $room_row['room_name'] : null;

		// Deliberately NOT using Channex_model::cancel_booking() — it has a latent bug
		// (a raw CONCAT() SQL fragment gets bound as a literal string, see PLAN_CHANNEL.md §1).
		$this->CI->db->where('booking_id', $local_booking_id)->update('booking', array('state' => CANCELLED));

		$this->audit($company_id, 'channel_booking_cancelled', $room_name,
			"Channex cancellation received for booking $channex_booking_id.");

		if ($block) {
			$this->CI->channel_reservations->upsert($channex_booking_id, array('status' => 'cancelled'));
			$this->CI->push_queue->enqueue($block['room_id'], $block['check_in_date'], $block['check_out_date'], 'channel_booking_cancelled');
		}

		return array('ok' => true, 'message' => 'cancelled', 'local_booking_id' => $local_booking_id);
	}

	private function resolve_room_type($room, $company_id)
	{
		if (empty($room['room_type_id'])) {
			return null;
		}

		$ota_x_company = $this->CI->Channex_model->get_channex_x_company(null, $company_id);
		$ota_x_company_id = is_array($ota_x_company) && isset($ota_x_company['ota_x_company_id']) ? $ota_x_company['ota_x_company_id'] : null;

		return $this->CI->Channex_model->get_osgrandhorizon_room_type_id($room['room_type_id'], $ota_x_company_id);
	}

	/** Picks a free physical room of the given room_type for [arrival, departure) — room-type pooling, §6. */
	private function resolve_specific_room($room_type_id, $arrival, $departure, $company_id)
	{
		$candidates = $this->CI->db->where(array('room_type_id' => $room_type_id, 'company_id' => $company_id))
			->get('room')->result_array();

		foreach ($candidates as $candidate) {
			$conflict_sql = "SELECT COUNT(*) AS cnt FROM booking_block bb
				JOIN booking b ON b.booking_id = bb.booking_id
				WHERE bb.room_id = ? AND b.state NOT IN (" . CANCELLED . ", " . DELETED . ", " . NO_SHOW . ")
					AND DATE(bb.check_in_date) < ? AND DATE(bb.check_out_date) > ?";
			$row = $this->CI->db->query($conflict_sql, array($candidate['room_id'], $departure, $arrival))->row_array();

			if ((int) $row['cnt'] === 0) {
				return $candidate;
			}
		}

		return null;
	}

	private function find_or_create_customer($booking, $company_id)
	{
		$customer = isset($booking['customer']) ? $booking['customer'] : array();
		$name = trim((isset($customer['name']) ? $customer['name'] : '') . ' ' . (isset($customer['surname']) ? $customer['surname'] : ''));
		$email = isset($customer['mail']) ? $customer['mail'] : null; // may be an OTA alias — never assume it's reachable long-term

		$this->CI->db->insert('customer', array(
			'customer_name' => $name ?: 'Guest',
			'email'         => $email,
			'phone'         => isset($customer['phone']) ? $customer['phone'] : null,
			'country'       => isset($customer['country']) ? $customer['country'] : null,
			'company_id'    => $company_id,
			'is_deleted'    => 0,
		));

		return $this->CI->db->insert_id();
	}

	private function save_channel_reservation($booking, $local_booking_id, $channex_booking_id, $ota_name, $room_name, $arrival, $departure)
	{
		$customer = isset($booking['customer']) ? $booking['customer'] : array();
		$occupancy = isset($booking['rooms'][0]['occupancy']) ? $booking['rooms'][0]['occupancy'] : (isset($booking['occupancy']) ? $booking['occupancy'] : null);
		$amount = isset($booking['amount']) ? (float) $booking['amount'] : null;
		$commission = isset($booking['ota_commission']) ? (float) $booking['ota_commission'] : 0;

		// Card/guarantee fields are never parsed or stored — see PLAN_CHANNEL.md §2d.
		$sanitized = $booking;
		unset($sanitized['guarantee']);

		$this->CI->channel_reservations->upsert($channex_booking_id, array(
			'local_booking_id'     => $local_booking_id,
			'ota_reservation_code' => isset($booking['ota_reservation_code']) ? $booking['ota_reservation_code'] : null,
			'channel_code'         => $ota_name,
			'status'               => $booking['status'],
			'guest_name'           => trim((isset($customer['name']) ? $customer['name'] : '') . ' ' . (isset($customer['surname']) ? $customer['surname'] : '')),
			'guest_email_alias'    => isset($customer['mail']) ? $customer['mail'] : null,
			'occupancy_json'       => $occupancy ? json_encode($occupancy) : null,
			'arrival_date'         => $arrival,
			'departure_date'       => $departure,
			'gross_amount_cents'   => $amount !== null ? (int) round($amount * 100) : null,
			'payout_cents'         => $amount !== null ? (int) round(($amount - $commission) * 100) : null,
			'currency'             => isset($booking['currency']) ? $booking['currency'] : null,
			'raw_payload_json'     => json_encode($sanitized),
		));

		$this->CI->channel_reservations->write_ota_bookings_companion($channex_booking_id, $ota_name, $local_booking_id, $arrival, $departure);
	}

	/** Same-day turnover: if another stay in this room ends the day this one begins, queue a cleaning request. */
	private function maybe_flag_turnover($room_id, $local_booking_id, $arrival, $company_id)
	{
		$sql = "SELECT COUNT(*) AS cnt FROM booking_block bb
			JOIN booking b ON b.booking_id = bb.booking_id
			WHERE bb.room_id = ? AND DATE(bb.check_out_date) = ? AND b.state NOT IN (" . CANCELLED . ", " . DELETED . ")";
		$row = $this->CI->db->query($sql, array($room_id, $arrival))->row_array();

		if ((int) $row['cnt'] > 0) {
			$this->CI->hk->request_cleaning($company_id, $room_id, $local_booking_id, $arrival);
		}
	}

	private function resolve_source_constant($ota_name)
	{
		if (!$ota_name) {
			return SOURCE_CHANNEX;
		}

		$name = strtolower($ota_name);
		if (strpos($name, 'booking.com') !== false || strpos($name, 'booking_dot_com') !== false) {
			return SOURCE_BOOKING_DOT_COM;
		}
		if (strpos($name, 'airbnb') !== false) {
			return SOURCE_AIRBNB;
		}
		if (strpos($name, 'expedia') !== false) {
			return SOURCE_EXPEDIA;
		}
		if (strpos($name, 'agoda') !== false) {
			return SOURCE_AGODA;
		}

		return SOURCE_CHANNEX; // connected via Channex but not one of our named OTA constants
	}

	private function normalize_time($time_str)
	{
		$ts = strtotime($time_str);
		return $ts ? date('H:i', $ts) : '15:00';
	}

	private function audit($company_id, $type, $room, $message)
	{
		$this->CI->load->model('audit_log_model', 'panther_audit_for_channel');
		$this->CI->panther_audit_for_channel->add($company_id, $type, $room, null, $message);
	}
}
