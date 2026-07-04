<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * CLI-only integration test harness for panther_channel's Channel_normalizer,
 * run against the real local DB (this codebase has no PHPUnit/test framework
 * installed anywhere — see PLAN_CHANNEL.md; adding one from scratch for a
 * single extension in a CI3 app this tightly coupled to superglobals was a
 * worse trade than reusing CI's own native CLI controller support, which
 * lets us exercise the real normalizer against the real DB with no mocking).
 *
 * Run: docker exec docker-php-1 php /app/public/index.php panther_channel_tests run
 *
 * Assumes migration 004/005 applied and a test ota_x_company + ota_room_types
 * mapping exist for company_id=1 (see RUNBOOK_CHANNEL.md "Running the tests").
 */
class Panther_channel_tests extends CI_Controller {

	private $pass = 0;
	private $fail = 0;
	private $company_id;
	private $fixtures_path;

	public function __construct()
	{
		parent::__construct();

		if (!$this->input->is_cli_request()) {
			show_404();
		}

		foreach (array('panther_channel', 'panther_audit_log', 'panther_housekeeping') as $module) {
			$this->load->add_package_path(APPPATH . 'extensions/' . $module . '/');
		}

		$this->load->library('Channel_normalizer');
		$this->load->model('channel_reservations_model', 'channel_reservations');
		$this->load->model('channel_push_queue_model', 'push_queue');
		$this->load->model('audit_log_model', 'panther_audit');

		$this->company_id = 1;
		$this->fixtures_path = APPPATH . 'extensions/panther_channel/tests/fixtures/';
	}

	public function run()
	{
		echo "=== panther_channel normalization tests ===\n";

		$this->_cleanup();
		$this->_test_new_booking_dot_com();
		$this->_test_new_airbnb();
		$this->_test_idempotent_redelivery();
		$this->_test_modification();
		$this->_test_turnover();
		$this->_test_cancellation();
		$this->_test_conflict_not_overwritten();
		$this->_test_push_queue_enqueued();

		echo "\n=== {$this->pass} passed, {$this->fail} failed ===\n";
		if ($this->fail > 0) {
			echo "SOME TESTS FAILED\n";
		}
	}

	private function _test_new_booking_dot_com()
	{
		echo "\n-- new booking: Booking.com --\n";
		$booking = $this->_fixture('booking_com_new');
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert($result['ok'], 'normalize() reports ok');
		$local = $this->db->where('booking_id', $result['local_booking_id'])->get('booking')->row_array();
		$this->assert($local && (int) $local['source'] === (int) SOURCE_BOOKING_DOT_COM, 'source = SOURCE_BOOKING_DOT_COM');
		$this->assert($local && (int) $local['state'] === (int) RESERVATION, 'state = RESERVATION');

		$block = $this->db->where('booking_id', $result['local_booking_id'])->get('booking_block')->row_array();
		$room = $block ? $this->db->where('room_id', $block['room_id'])->get('room')->row_array() : null;
		$this->assert($room && $room['room_name'] === 'Sunset', 'assigned to Sunset (mapped room_type)');
		$this->assert($block && substr($block['check_in_date'], 0, 10) === '2026-08-10', 'check_in_date correct');

		$cr = $this->channel_reservations->find_by_channex_id('bcom-test-0001');
		$this->assert($cr && $cr['guest_name'] === 'Maria Fernandez', 'channel_reservations guest_name correct');
		$this->assert($cr && (int) $cr['gross_amount_cents'] === 45000, 'gross_amount_cents = 45000 (decimal->cents conversion)');
		$this->assert($cr && (int) $cr['payout_cents'] === 38250, 'payout_cents = gross - ota_commission, in cents');

		$ota_booking = $this->db->where('ota_booking_id', 'bcom-test-0001')->get('ota_bookings')->row_array();
		$this->assert($ota_booking && (int) $ota_booking['pms_booking_id'] === (int) $result['local_booking_id'], 'ota_bookings companion row written (get_related_pms_booking_ids compatibility)');
	}

	private function _test_new_airbnb()
	{
		echo "\n-- new booking: Airbnb --\n";
		$booking = $this->_fixture('airbnb_new');
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert($result['ok'], 'normalize() reports ok');
		$local = $this->db->where('booking_id', $result['local_booking_id'])->get('booking')->row_array();
		$this->assert($local && (int) $local['source'] === (int) SOURCE_AIRBNB, 'source = SOURCE_AIRBNB');

		$block = $this->db->where('booking_id', $result['local_booking_id'])->get('booking_block')->row_array();
		$room = $block ? $this->db->where('room_id', $block['room_id'])->get('room')->row_array() : null;
		$this->assert($room && in_array($room['room_name'], array('Sunrise1', 'Sunrise2')), 'assigned to a Double Room cabin (room-type pooling)');
	}

	private function _test_idempotent_redelivery()
	{
		echo "\n-- idempotency: same NEW event delivered twice --\n";
		$booking = $this->_fixture('booking_com_new');
		$this->channel_normalizer->normalize($booking, $this->company_id);

		$count_matching = (int) $this->db->select('COUNT(*) AS cnt')
			->from('booking b')
			->join('customer c', 'c.customer_id = b.booking_customer_id')
			->where('c.customer_name', 'Maria Fernandez')
			->get()->row_array()['cnt'];

		$this->assert($count_matching === 1, 'still exactly 1 local booking for Maria Fernandez after re-ingesting the same NEW event');
	}

	private function _test_modification()
	{
		echo "\n-- modification: Booking.com extends stay --\n";
		$booking = $this->_fixture('booking_com_modified');
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert($result['ok'], 'normalize() reports ok');
		$block = $this->db->where('booking_id', $result['local_booking_id'])->get('booking_block')->row_array();
		$this->assert($block && substr($block['check_out_date'], 0, 10) === '2026-08-14', 'check_out_date updated to extended date, not duplicated');

		$cr = $this->channel_reservations->find_by_channex_id('bcom-test-0001');
		$this->assert($cr && (int) $cr['gross_amount_cents'] === 60000, 'channel_reservations amount updated on modification');
	}

	private function _test_turnover()
	{
		echo "\n-- turnover: new Sunset booking starts the day the previous one departs --\n";
		$booking = $this->_fixture('booking_com_turnover_new');
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert($result['ok'], 'normalize() reports ok');
		$block = $this->db->where('booking_id', $result['local_booking_id'])->get('booking_block')->row_array();

		$cleaning = $this->db->where(array('room_id' => $block['room_id'], 'request_date' => '2026-08-14', 'status' => 'requested'))
			->get('cleaning_requests')->row_array();
		$this->assert((bool) $cleaning, 'same-day turnover raised a cleaning_requests entry for the departure date');
	}

	private function _test_cancellation()
	{
		echo "\n-- cancellation: Airbnb booking cancelled --\n";
		$booking = $this->_fixture('airbnb_cancelled');
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert($result['ok'], 'normalize() reports ok');
		$local = $this->db->where('booking_id', $result['local_booking_id'])->get('booking')->row_array();
		$this->assert($local && (int) $local['state'] === (int) CANCELLED, 'local booking state = CANCELLED (not deleted)');

		$cr = $this->channel_reservations->find_by_channex_id('abnb-test-0002');
		$this->assert($cr && $cr['status'] === 'cancelled', 'channel_reservations status = cancelled');
	}

	private function _test_conflict_not_overwritten()
	{
		echo "\n-- conflict: modification for a non-OTA local booking must NOT overwrite --\n";

		// Seed a manual (walk-in) local booking + a dangling ota_bookings link,
		// simulating "Channex thinks this ota id maps to a booking that's
		// actually a manual/direct reservation locally".
		$this->db->insert('customer', array('customer_name' => 'Manual Guest', 'company_id' => $this->company_id, 'is_deleted' => 0));
		$customer_id = $this->db->insert_id();
		$this->db->insert('booking', array(
			'company_id' => $this->company_id, 'booking_customer_id' => $customer_id,
			'state' => RESERVATION, 'source' => SOURCE_WALK_IN, 'adult_count' => 1, 'children_count' => 0,
		));
		$manual_booking_id = $this->db->insert_id();
		$this->db->insert('ota_bookings', array(
			'ota_booking_id' => 'conflict-test-0001', 'ota_type' => 'Booking.com', 'booking_type' => 'channex',
			'pms_booking_id' => $manual_booking_id, 'check_in_date' => '2026-09-01', 'check_out_date' => '2026-09-03',
			'create_date_time' => date('Y-m-d H:i:s'),
		));

		$audit_count_before = (int) $this->db->where(array('type' => 'channel_conflict_detected'))->count_all_results('audit_log');

		$booking = $this->_fixture('booking_com_new');
		$booking['id'] = 'conflict-test-0001';
		$booking['status'] = 'modified';
		$result = $this->channel_normalizer->normalize($booking, $this->company_id);

		$this->assert(!$result['ok'], 'normalize() reports NOT ok (conflict, refused to overwrite)');

		$still_manual = $this->db->where('booking_id', $manual_booking_id)->get('booking')->row_array();
		$this->assert((int) $still_manual['source'] === (int) SOURCE_WALK_IN, 'manual booking source untouched');

		$audit_count_after = (int) $this->db->where(array('type' => 'channel_conflict_detected'))->count_all_results('audit_log');
		$this->assert($audit_count_after > $audit_count_before, 'channel_conflict_detected audit entry was written');
	}

	private function _test_push_queue_enqueued()
	{
		echo "\n-- push-back: ingesting a booking enqueues a channel_push_queue row --\n";
		$pending = $this->push_queue->get_pending_coalesced();
		$this->assert(count($pending) > 0, 'at least one room has a pending push-queue entry after the tests above');
	}

	private function _fixture($name)
	{
		$json = file_get_contents($this->fixtures_path . $name . '.json');
		return json_decode($json, true);
	}

	private function assert($condition, $label)
	{
		if ($condition) {
			$this->pass++;
			echo "  PASS: $label\n";
		} else {
			$this->fail++;
			echo "  FAIL: $label\n";
		}
	}

	/** Removes rows from a previous test run so this script is safely re-runnable. */
	private function _cleanup()
	{
		$channex_ids = array('bcom-test-0001', 'abnb-test-0002', 'bcom-test-0005', 'conflict-test-0001');

		$this->db->where_in('channex_booking_id', $channex_ids)->delete('channel_reservations');

		$ota_rows = $this->db->where_in('ota_booking_id', $channex_ids)->get('ota_bookings')->result_array();
		$booking_ids = array_column($ota_rows, 'pms_booking_id');

		$this->db->where_in('ota_booking_id', $channex_ids)->delete('ota_bookings');

		if ($booking_ids) {
			$this->db->where_in('booking_id', $booking_ids)->delete('booking_block');
			$this->db->where_in('booking_id', $booking_ids)->delete('booking');
		}

		$this->db->where('company_id', $this->company_id)->where_in('reason', array('channel_booking_new', 'channel_booking_modified', 'channel_booking_cancelled'))->delete('channel_push_queue');
		$this->db->where('customer_name', 'Manual Guest')->delete('customer');
	}
}
