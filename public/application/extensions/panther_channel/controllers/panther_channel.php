<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

/**
 * Admin UI only (Channels page, mapping editor, interactive action buttons)
 * — requires login like every other panther_* page. The webhook receiver
 * and cron/CLI actions live in the CORE controller
 * public/application/controllers/panther_channel_public.php instead — see
 * that file's docblock for why they can't live here (MY_Controller's
 * $module_permission gate 404s any HMVC-module route when there's no
 * session, independent of the permission_model.php whitelist).
 */
class Panther_channel extends Panther_controller {

	public function __construct()
	{
		parent::__construct();
		$this->load->library('Channex_client');
		$this->load->model('channel_reservations_model', 'channel_reservations');
		$this->load->model('channel_push_queue_model', 'push_queue');
		$this->load->model('channel_sync_log_model', 'sync_log');
		$this->load->model('webhook_events_model', 'webhook_events');
	}

	public function index()
	{
		$this->load->model('Channex_model');
		$ota_x_company = $this->Channex_model->get_channex_x_company(null, $this->company_id);

		$this->panther_render('channels_index', array(
			'is_configured'    => $this->channex_client->is_configured(),
			'ota_x_company'    => $ota_x_company,
			'room_mappings'    => $this->get_room_mappings(),
			'reservations'     => $this->channel_reservations->get_all(),
			'sync_log'         => $this->sync_log->get_recent(50),
			'failed_count'     => $this->sync_log->get_failed_count(),
			'pending_push'     => $this->push_queue->count_pending(),
			'last_webhook_at'  => $this->webhook_events->get_last_received(),
		), 'channels');
	}

	public function save_mapping()
	{
		$room_id = $this->input->post('room_id');
		$ota_room_type_id = trim($this->input->post('ota_room_type_id'));
		$ota_rate_plan_id = trim($this->input->post('ota_rate_plan_id'));

		$this->load->model('Channex_model');
		$this->load->model('Room_model');

		$room = $this->Room_model->get_room($room_id);
		if (!$room) {
			echo json_encode(array('success' => false, 'message' => 'Unknown room'));
			return;
		}

		$ota_x_company = $this->Channex_model->get_channex_x_company(null, $this->company_id);
		if (!$ota_x_company) {
			echo json_encode(array('success' => false, 'message' => 'Connect a Channex property first (Test Connection).'));
			return;
		}

		$ota_x_company_id = $ota_x_company['ota_x_company_id'];

		if ($ota_room_type_id !== '') {
			$existing = $this->Channex_model->get_room_type($ota_x_company_id, $ota_room_type_id);
			if ($existing) {
				$this->Channex_model->update_room_type($ota_x_company_id, $ota_room_type_id, $room['room_type_id']);
			} else {
				$this->Channex_model->create_or_update_room_type($ota_x_company_id, $ota_room_type_id, $room['room_type_id'], $this->company_id);
			}
		}

		if ($ota_rate_plan_id !== '') {
			$existing_rate = $this->Channex_model->get_rate_plan($ota_x_company_id, $ota_room_type_id, null, $ota_rate_plan_id);
			if ($existing_rate) {
				$this->Channex_model->update_rate_plan($ota_x_company_id, $ota_room_type_id, null, $ota_rate_plan_id, $this->company_id);
			} else {
				$this->Channex_model->create_or_update_rate_plan($ota_x_company_id, $ota_room_type_id, null, $ota_rate_plan_id, $this->company_id);
			}
		}

		$this->panther_audit->add($this->company_id, 'channel_mapping_saved', $room['room_name'], $this->user_id,
			"Mapped {$room['room_name']} to Channex room_type_id=$ota_room_type_id, rate_plan_id=$ota_rate_plan_id");

		echo json_encode(array('success' => true));
	}

	public function test_connection()
	{
		$result = $this->channex_client->get_property();
		$this->sync_log->log('out', 'test_connection', $result['success'] ? 'success' : 'failed', $result['error'], $result['body']);

		if ($result['success'] && isset($result['body']['data']['attributes'])) {
			$attrs = $result['body']['data']['attributes'];
			$this->load->model('Channex_model');
			$existing = $this->Channex_model->get_channex_x_company(null, $this->company_id);

			$data = array(
				'company_id'      => $this->company_id,
				'ota_property_id' => getenv('CHANNEX_PROPERTY_ID'),
				'is_active'       => 1,
			);

			if ($existing) {
				$data['ota_x_company_id'] = $existing['ota_x_company_id'];
				$this->Channex_model->save_channex_company($data, true);
			} else {
				$this->Channex_model->save_token(array(
					'ota_id'       => 1, // 'channex' row, confirmed seeded — PLAN_CHANNEL.md §1
					'email'        => $this->user_email,
					'meta_data'    => json_encode(array('connected_at' => date('c'))), // no secrets — those stay in .env only
					'company_id'   => $this->company_id,
					'created_date' => date('Y-m-d H:i:s'),
				));
				$manager = $this->Channex_model->get_channex_data($this->company_id);
				$data['ota_manager_id'] = $manager['id'];
				$this->Channex_model->save_channex_company($data);
			}

			$this->Channex_model->save_properties(array(
				'ota_manager_id'         => $data['ota_manager_id'],
				'company_id'             => $this->company_id,
				'channex_property_data'  => json_encode($attrs),
			));
		}

		echo json_encode($result);
	}

	public function pull_future_bookings()
	{
		// Delegates to the same reconcile logic the core public controller uses —
		// calling it directly here would duplicate the method, so instead we just
		// hit our own public endpoint internally via a loopback request, matching
		// the existing precedent in core cron.php for cross-controller triggering.
		$secret = getenv('CRON_AUTH_SECRET');
		$url = base_url() . 'panther_channel_public/cron_reconcile' . ($secret ? '?secret=' . urlencode($secret) : '');
		echo $this->_loopback($url);
	}

	public function full_sync()
	{
		$this->load->model('Room_model');
		$rooms = $this->Room_model->get_rooms($this->company_id);
		$horizon_end = date('Y-m-d', strtotime('+365 days'));
		$today = date('Y-m-d');

		foreach ($rooms as $room) {
			$this->push_queue->enqueue($room['room_id'], $today, $horizon_end, 'full_sync');
		}

		$this->panther_audit->add($this->company_id, 'channel_full_sync_queued', null, $this->user_id,
			'Full 365-day availability sync queued for all rooms.');

		echo json_encode(array('success' => true, 'rooms_queued' => count($rooms)));
	}

	private function _loopback($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 25);
		$response = curl_exec($ch);
		$err = curl_error($ch);
		curl_close($ch);
		return $err ? json_encode(array('success' => false, 'message' => $err)) : $response;
	}

	private function get_room_mappings()
	{
		$this->load->model('Room_model');
		$this->load->model('Channex_model');

		$rooms = $this->Room_model->get_rooms($this->company_id);
		$mappings = array();

		foreach ($rooms as $room) {
			$room_types = $this->Channex_model->get_channex_room_types_by_id($room['room_type_id'], $this->company_id);

			$mappings[] = array(
				'room'             => $room,
				'ota_room_type_id' => !empty($room_types) ? $room_types[0]['ota_room_type_id'] : '',
				'ota_rate_plan_id' => '',
			);
		}

		return $mappings;
	}
}
