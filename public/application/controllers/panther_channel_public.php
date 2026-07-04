<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Public entry points for the panther_channel extension: the Channex webhook
 * receiver and the cron/CLI sync actions. These CANNOT live inside the
 * panther_channel HMVC extension controller — MY_Controller.php has a second
 * gate beyond permission_model.php's is_public() whitelist (the
 * $module_permission loop, ~line 161) that show_404()s any HMVC-module route
 * whenever $company_id is empty, which it always is for a genuinely
 * unauthenticated request (no session). That gate only fires when
 * $this->router->fetch_module() is non-empty, i.e. only for MX-routed
 * extension controllers — a plain core controller like this one (extending
 * CI_Controller directly, exactly like core's own cron.php) never triggers
 * it at all. So: this thin file is the unavoidable extra core touch: the
 * bare minimum needed to make ANY unauthenticated endpoint reachable in this
 * app's architecture. All real logic still lives in the extension (Channex_client,
 * Channel_normalizer, the channel_* models) — this file just dispatches to it.
 * See PLAN_CHANNEL.md §3 for the originally-flagged core touch
 * (permission_model.php) — this is the necessary companion to it.
 */
class Panther_channel_public extends CI_Controller
{
	public function __construct()
	{
		parent::__construct();

		foreach (array('panther_channel', 'panther_audit_log', 'panther_housekeeping', 'panther_shell') as $module) {
			$this->load->add_package_path(APPPATH . 'extensions/' . $module . '/');
		}

		$this->load->library('Channex_client');
		$this->load->library('Channel_normalizer');
		$this->load->model('channel_reservations_model', 'channel_reservations');
		$this->load->model('channel_push_queue_model', 'push_queue');
		$this->load->model('channel_sync_log_model', 'sync_log');
		$this->load->model('webhook_events_model', 'webhook_events');
		$this->load->model('audit_log_model', 'panther_audit');
		$this->load->model('Channex_model');
		$this->load->model('Room_model');
	}

	// ---------------------------------------------------------------
	// Public: webhook receiver (Channex calls this, no session)
	// ---------------------------------------------------------------

	public function webhook()
	{
		// Read the raw $_SERVER key directly rather than CI's get_request_header()
		// normalization (which doesn't reliably round-trip a multi-word custom
		// header name back to its original casing under php-fpm/nginx — verified
		// empirically, not assumed).
		$provided = isset($_SERVER['HTTP_X_PANTHER_WEBHOOK_SECRET']) ? $_SERVER['HTTP_X_PANTHER_WEBHOOK_SECRET'] : false;
		$expected = getenv('CHANNEX_WEBHOOK_SECRET');
		$valid = $expected && $provided && hash_equals($expected, $provided);

		$raw = file_get_contents('php://input');
		$payload = json_decode($raw, true);

		if (!$valid) {
			$this->sync_log->log('in', 'webhook_receive', 'failed', 'Missing or invalid shared-secret header');
			$this->output->set_status_header(401);
			echo json_encode(array('success' => false));
			return;
		}

		$event_type = isset($payload['event']) ? $payload['event'] : 'unknown';
		$booking_id = isset($payload['payload']['booking_id']) ? $payload['payload']['booking_id'] : null;
		$revision_id = isset($payload['payload']['revision_id']) ? $payload['payload']['revision_id'] : null;

		$record = $this->webhook_events->record($event_type, $booking_id, $revision_id, true);
		$this->sync_log->log('in', 'webhook_receive', 'success', null, array('event' => $event_type, 'duplicate' => $record['is_duplicate']));

		// Respond 2xx immediately; the payload has almost no data anyway
		// (PLAN_CHANNEL.md §2b) — the real work is always the feed pull,
		// triggered here best-effort.
		echo json_encode(array('success' => true));
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}

		$company_id = $this->_resolve_single_company_id();
		try {
			$this->_run_reconcile($company_id);
			$this->webhook_events->mark_processed($record['id']);
		} catch (Exception $e) {
			// Swallowed deliberately: if this fails, the next scheduled
			// cron_reconcile run picks it up regardless — no data loss.
			$this->sync_log->log('in', 'webhook_triggered_reconcile', 'failed', $e->getMessage());
		}
	}

	// ---------------------------------------------------------------
	// Public: cron/CLI actions (system crontab, no session — secret-gated
	// individually via CRON_AUTH_SECRET, same pattern as core cron.php)
	// ---------------------------------------------------------------

	public function cron_test_credentials($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;
		$result = $this->channex_client->get_property();
		$this->sync_log->log('out', 'cron_test_credentials', $result['success'] ? 'success' : 'failed', $result['error']);
		echo json_encode($result);
	}

	public function cron_sync_rooms($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;
		echo json_encode(array('success' => true, 'message' => 'sync-rooms: no PMS-side room config changes to push yet'));
	}

	public function cron_sync_prices($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;
		echo json_encode(array('success' => true, 'message' => 'sync-prices: restrictions endpoint is ready in Channex_client, needs a price source to call it with'));
	}

	public function cron_sync_availability($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;

		$company_id = $this->_resolve_single_company_id();
		$rooms = $this->Room_model->get_rooms($company_id);
		$horizon_end = date('Y-m-d', strtotime('+365 days'));
		$today = date('Y-m-d');

		foreach ($rooms as $room) {
			$this->push_queue->enqueue($room['room_id'], $today, $horizon_end, 'cron_sync_availability');
		}

		echo json_encode(array('success' => true, 'rooms_queued' => count($rooms)));
	}

	/** Meant to run every minute via crontab — drains the throttled push queue. */
	public function cron_push_queue($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;
		echo json_encode($this->_drain_push_queue());
	}

	/** First-connect backfill AND ongoing reconciliation are the exact same call. */
	public function cron_reconcile($secret = null)
	{
		if (!$this->_check_cron_secret($secret)) return;
		$company_id = $this->_resolve_single_company_id();
		echo json_encode($this->_run_reconcile($company_id));
	}

	// ---------------------------------------------------------------
	// Shared internals
	// ---------------------------------------------------------------

	private function _check_cron_secret($secret_param)
	{
		$expected = getenv('CRON_AUTH_SECRET');
		$provided = $secret_param !== null ? $secret_param : $this->input->get('secret');

		if ($expected && (!$provided || !hash_equals($expected, $provided))) {
			$this->output->set_status_header(401);
			echo json_encode(array('success' => false, 'message' => 'unauthorized'));
			return false;
		}

		return true;
	}

	private function _resolve_single_company_id()
	{
		$row = $this->db->select('company_id')->order_by('company_id', 'ASC')->limit(1)->get('company')->row_array();
		return $row ? $row['company_id'] : null;
	}

	private function _run_reconcile($company_id)
	{
		$feed = $this->channex_client->get_revision_feed();
		$this->sync_log->log('in', 'pull_feed', $feed['success'] ? 'success' : 'failed', $feed['error']);

		if (!$feed['success']) {
			return array('success' => false, 'message' => $feed['error']);
		}

		$items = isset($feed['body']['data']) ? $feed['body']['data'] : array();
		$processed = 0;
		$errors = array();

		foreach ($items as $item) {
			$revision_id = isset($item['id']) ? $item['id'] : (isset($item['revision_id']) ? $item['revision_id'] : null);

			$booking_data = null;
			if (isset($item['booking']['attributes'])) {
				$booking_data = $item['booking']['attributes'];
			} elseif (isset($item['attributes'])) {
				$booking_data = $item['attributes'];
			} elseif (isset($item['booking_id'])) {
				$fetch = $this->channex_client->get_booking($item['booking_id']);
				if ($fetch['success'] && isset($fetch['body']['data']['attributes'])) {
					$booking_data = $fetch['body']['data']['attributes'];
				}
			}

			if (!$booking_data) {
				$errors[] = 'Could not resolve booking data for feed item (revision ' . $revision_id . ')';
				continue;
			}

			$result = $this->channel_normalizer->normalize($booking_data, $company_id);
			$this->sync_log->log('in', 'normalize_booking', $result['ok'] ? 'success' : 'failed', $result['ok'] ? null : $result['message']);

			if ($result['ok']) {
				$processed++;
				if ($revision_id) {
					$this->channex_client->ack_revision($revision_id);
				}
			} else {
				$errors[] = $result['message'];
			}
		}

		return array('success' => true, 'processed' => $processed, 'errors' => $errors);
	}

	/** Coalesces channel_push_queue by room and sends at most 1 availability call per run. */
	private function _drain_push_queue()
	{
		$coalesced = $this->push_queue->get_pending_coalesced();
		if (empty($coalesced)) {
			return array('success' => true, 'rooms' => 0);
		}

		$availability_values = array();
		$queue_ids_by_room = array();

		foreach ($coalesced as $row) {
			$room = $this->Room_model->get_room($row['room_id']);
			if (!$room) continue;

			$room_type_ids = $this->Channex_model->get_channex_room_types_by_id($room['room_type_id']);
			if (empty($room_type_ids)) continue; // unmapped room — nothing to push

			foreach ($room_type_ids as $mapping) {
				$availability_values[] = array(
					'room_type_id' => $mapping['ota_room_type_id'],
					'date_from'    => $row['date_from'],
					'date_to'      => $row['date_to'],
					'availability' => $this->_compute_room_availability($row['room_id'], $row['date_from'], $row['date_to']),
				);
			}

			$queue_ids_by_room[$row['room_id']] = $row['queue_ids'];
		}

		if (empty($availability_values)) {
			return array('success' => true, 'rooms' => 0, 'message' => 'no mapped rooms with pending changes');
		}

		$result = $this->channex_client->push_availability($availability_values);
		$this->sync_log->log('out', 'push_availability', $result['success'] ? 'success' : 'failed', $result['error'], $availability_values);

		foreach ($queue_ids_by_room as $room_id => $queue_ids) {
			if ($result['success']) {
				$this->push_queue->mark_sent($queue_ids);
			} else {
				$this->push_queue->mark_failed($queue_ids, $result['error']);
				// A failed availability push is a double-booking risk — surfaced via
				// the admin error feed (channel_sync_log) and this audit entry.
				$this->panther_audit->add($this->_resolve_single_company_id(), 'channel_push_failed', null, null,
					'Availability push failed: ' . $result['error']);
			}
		}

		return array('success' => $result['success'], 'rooms' => count($queue_ids_by_room), 'error' => $result['error']);
	}

	/** 0 if the room is booked for any night in [date_from, date_to), else 1 (single physical unit per room). */
	private function _compute_room_availability($room_id, $date_from, $date_to)
	{
		$sql = "SELECT COUNT(*) AS cnt FROM booking_block bb
			JOIN booking b ON b.booking_id = bb.booking_id
			WHERE bb.room_id = ? AND b.state NOT IN (" . CANCELLED . ", " . DELETED . ", " . NO_SHOW . ")
				AND DATE(bb.check_in_date) < ? AND DATE(bb.check_out_date) > ?";
		$row = $this->db->query($sql, array($room_id, $date_to, $date_from))->row_array();
		return ((int) $row['cnt'] > 0) ? 0 : 1;
	}
}
