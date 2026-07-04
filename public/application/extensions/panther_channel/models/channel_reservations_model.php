<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Rich per-reservation record (see PLAN_CHANNEL.md §4) plus a companion
 * ota_bookings row written alongside it, so the existing core method
 * Channex_model::get_related_pms_booking_ids() keeps working as our shared
 * idempotency lookup (§1/§11.4).
 */
class Channel_reservations_model extends CI_Model {

	public function find_by_channex_id($channex_booking_id)
	{
		return $this->db->where('channex_booking_id', $channex_booking_id)
			->get('channel_reservations')->row_array();
	}

	public function upsert($channex_booking_id, array $data)
	{
		$existing = $this->find_by_channex_id($channex_booking_id);
		$data['channex_booking_id'] = $channex_booking_id;

		if ($existing) {
			$data['updated_at'] = date('Y-m-d H:i:s');
			$this->db->where('id', $existing['id'])->update('channel_reservations', $data);
			return $existing['id'];
		}

		$data['received_at'] = date('Y-m-d H:i:s');
		$this->db->insert('channel_reservations', $data);
		return $this->db->insert_id();
	}

	/**
	 * Keeps core's ota_bookings table populated so
	 * Channex_model::get_related_pms_booking_ids($channex_booking_id) — our
	 * shared dedupe check — continues to find our bookings.
	 */
	public function write_ota_bookings_companion($channex_booking_id, $ota_type, $pms_booking_id, $check_in_date, $check_out_date)
	{
		$existing = $this->db->where('ota_booking_id', $channex_booking_id)->get('ota_bookings')->row_array();

		$data = array(
			'ota_booking_id'    => $channex_booking_id,
			'ota_type'          => $ota_type,
			'booking_type'      => 'channex',
			'pms_booking_id'    => $pms_booking_id,
			'check_in_date'     => $check_in_date,
			'check_out_date'    => $check_out_date,
		);

		if ($existing) {
			$this->db->where('id', $existing['id'])->update('ota_bookings', $data);
		} else {
			$data['create_date_time'] = date('Y-m-d H:i:s');
			$this->db->insert('ota_bookings', $data);
		}
	}

	public function get_all($search = null)
	{
		if ($search) {
			$this->db->group_start()
				->like('guest_name', $search)
				->or_like('channex_booking_id', $search)
				->or_like('ota_reservation_code', $search)
				->group_end();
		}
		return $this->db->order_by('received_at', 'DESC')->limit(200)->get('channel_reservations')->result_array();
	}
}
