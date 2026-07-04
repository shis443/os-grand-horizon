<?php defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('panther_seed_demo_july')) {
	/**
	 * Seeds a demo July 2026 sheet for the 3 fixed cabins: a mix of
	 * check-in/reserved/checkout/turnover days, a couple of surcharges,
	 * a couple of cash transactions, and one open cleaning request — enough
	 * to see every grid/housekeeping/ledger state without real guest data.
	 */
	function panther_seed_demo_july($company_id, $db)
	{
		$rooms = array();
		foreach ($db->where('company_id', $company_id)->get('room')->result_array() as $r) {
			$rooms[$r['room_name']] = $r['room_id'];
		}
		if (empty($rooms['Sunset']) || empty($rooms['Sunrise1']) || empty($rooms['Sunrise2'])) {
			return; // fixed-room setup not in place; nothing to seed
		}

		$db->update('company', array('selling_date' => '2026-07-04'), array('company_id' => $company_id));
		$db->where('company_id', $company_id)->update('app_state', array('active_month' => 7, 'active_year' => 2026));

		$make_customer = function ($name) use ($db, $company_id) {
			$db->insert('customer', array('customer_name' => $name, 'company_id' => $company_id, 'is_deleted' => 0));
			return $db->insert_id();
		};

		$make_booking = function ($customer_id, $state, $source, $color) use ($db, $company_id) {
			$db->insert('booking', array(
				'company_id'          => $company_id,
				'booking_customer_id' => $customer_id,
				'state'               => $state,
				'source'              => $source,
				'color'               => $color,
				'adult_count'         => 2,
				'children_count'      => 0,
			));
			return $db->insert_id();
		};

		$make_block = function ($booking_id, $room_id, $check_in, $check_out) use ($db) {
			$db->insert('booking_block', array(
				'booking_id'     => $booking_id,
				'room_id'        => $room_id,
				'check_in_date'  => $check_in,
				'check_out_date' => $check_out,
			));
			return $db->insert_id();
		};

		// SOURCE_AIRBNB = -6, SOURCE_WALK_IN = 0 (config/constants.php)
		$aru  = $make_customer('Aru');
		$noor = $make_customer('Noor');
		$kai  = $make_customer('Kai');
		$lior = $make_customer('Lior');
		$mira = $make_customer('Mira');

		$b1 = $make_booking($aru,  INHOUSE,     -6, '35c46a'); // Sunset, currently staying
		$m_b1 = $make_block($b1, $rooms['Sunset'], '2026-07-01 15:00:00', '2026-07-05 11:00:00');

		$b2 = $make_booking($noor, INHOUSE,     -6, '4f8dff'); // Sunrise1, currently staying
		$make_block($b2, $rooms['Sunrise1'], '2026-07-03 14:00:00', '2026-07-06 11:00:00');

		$b3 = $make_booking($kai,  RESERVATION,  0, ''); // Sunrise2, upcoming
		$make_block($b3, $rooms['Sunrise2'], '2026-07-05 15:00:00', '2026-07-08 11:00:00');

		$b4 = $make_booking($lior, RESERVATION, -2, ''); // Sunset turnover on the 5th
		$make_block($b4, $rooms['Sunset'], '2026-07-05 16:00:00', '2026-07-09 11:00:00');

		$b5 = $make_booking($mira, RESERVATION, -6, ''); // Sunrise1 turnover on the 6th
		$make_block($b5, $rooms['Sunrise1'], '2026-07-06 15:00:00', '2026-07-10 11:00:00');

		// Surcharges
		$db->insert('surcharges', array(
			'company_id' => $company_id, 'booking_id' => $b1, 'room' => 'Sunset', 'guest_name' => 'Aru',
			'date' => '2026-07-02', 'amount_eur' => 1500, 'reason' => 'minibar', 'cleared_bool' => 0,
			'created_at' => '2026-07-02 10:00:00',
		));
		$db->insert('surcharges', array(
			'company_id' => $company_id, 'booking_id' => $b2, 'room' => 'Sunrise1', 'guest_name' => 'Noor',
			'date' => '2026-07-03', 'amount_eur' => 3000, 'reason' => 'pet fee', 'cleared_bool' => 0,
			'created_at' => '2026-07-03 09:00:00',
		));

		// Cash register
		$db->insert('cash_transactions', array(
			'company_id' => $company_id, 'type' => 'in', 'amount_eur' => 10000, 'date' => '2026-07-01',
			'customer' => 'Aru', 'category' => 'Booking Deposit', 'handled_by' => 'Front Desk',
			'notes' => 'Deposit on arrival', 'created_at' => '2026-07-01 15:10:00',
		));
		$db->insert('cash_transactions', array(
			'company_id' => $company_id, 'type' => 'out', 'amount_eur' => 4200, 'date' => '2026-07-02',
			'customer' => null, 'category' => 'Supplies', 'handled_by' => 'Front Desk',
			'notes' => 'Cleaning supplies', 'created_at' => '2026-07-02 12:00:00',
		));

		// One open cleaning request (as if flagged from the grid) for Sunrise2
		$db->insert('cleaning_requests', array(
			'company_id' => $company_id, 'booking_id' => null, 'room_id' => $rooms['Sunrise2'],
			'request_date' => '2026-07-04', 'status' => 'requested', 'requested_at' => '2026-07-04 09:00:00',
		));
	}
}
