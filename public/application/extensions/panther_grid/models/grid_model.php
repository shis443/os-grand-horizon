<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Adapts core Booking_model/booking_room_history data into a month x room
 * grid (rows = days, one column per room). Reuses booking.state,
 * booking.color, booking.housekeeping_notes, booking_room_history's
 * check_in_date/check_out_date, customer.customer_name, and booking.source —
 * no new booking data is invented here, only a different presentation of it.
 */
class Grid_model extends CI_Model {

	const STATUS_CHECKIN  = 'checkin';
	const STATUS_RESERVED = 'reserved';
	const STATUS_CHECKOUT = 'checkout';
	const STATUS_TURNOVER = 'turnover'; // checkout & checkin same day

	public function get_rooms($company_id)
	{
		return $this->db->where('company_id', $company_id)->order_by('room_name', 'ASC')->get('room')->result_array();
	}

	/** All stays (booking_room_history rows) overlapping the given month, with guest/source/state. */
	public function get_month_stays($company_id, $year, $month)
	{
		$month_start = sprintf('%04d-%02d-01', $year, $month);
		$month_end   = date('Y-m-d', strtotime($month_start . ' +1 month'));

		$sql = "SELECT
				brh.booking_room_history_id, brh.room_id, brh.check_in_date, brh.check_out_date,
				b.booking_id, b.state, b.color, b.housekeeping_notes, b.source,
				c.customer_name
			FROM booking_block brh
			JOIN booking b ON b.booking_id = brh.booking_id
			JOIN room r ON r.room_id = brh.room_id
			LEFT JOIN customer c ON c.customer_id = b.booking_customer_id
			WHERE r.company_id = ?
				AND b.state NOT IN (" . CANCELLED . ", " . DELETED . ", " . NO_SHOW . ")
				AND DATE(brh.check_in_date) < ?
				AND DATE(brh.check_out_date) > ?
			ORDER BY brh.check_in_date ASC";

		return $this->db->query($sql, array($company_id, $month_end, $month_start))->result_array();
	}

	/**
	 * Builds cell_map[room_id][day_number] => array(status, stay) for the month,
	 * so the view can render a plain HTML table (rows=days, cols=rooms).
	 */
	public function build_cell_map($company_id, $year, $month)
	{
		$stays = $this->get_month_stays($company_id, $year, $month);
		$days_in_month = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));

		$map = array();

		foreach ($stays as $stay) {
			$check_in  = substr($stay['check_in_date'], 0, 10);
			$check_out = substr($stay['check_out_date'], 0, 10);

			for ($d = 1; $d <= $days_in_month; $d++) {
				$day_str = sprintf('%04d-%02d-%02d', $year, $month, $d);

				if ($day_str < $check_in || $day_str >= $check_out) {
					continue;
				}

				$status = self::STATUS_RESERVED;
				if ($day_str === $check_in) {
					$status = self::STATUS_CHECKIN;
				} elseif (date('Y-m-d', strtotime($check_out . ' -1 day')) === $day_str) {
					// last night of the stay — checkout happens the following morning,
					// but we surface it on the last occupied day for a same-day turnover view
					$status = ($status === self::STATUS_CHECKIN) ? self::STATUS_CHECKIN : $status;
				}

				if (!isset($map[$stay['room_id']][$d])) {
					$map[$stay['room_id']][$d] = array();
				}
				$map[$stay['room_id']][$d][] = array_merge($stay, array('cell_status' => $status));
			}

			// mark the checkout day itself (day of check_out_date) distinctly
			$checkout_day = (int) date('j', strtotime($check_out));
			$checkout_month = (int) date('n', strtotime($check_out));
			if ($checkout_month === (int) $month && $checkout_day >= 1 && $checkout_day <= $days_in_month) {
				if (!isset($map[$stay['room_id']][$checkout_day])) {
					$map[$stay['room_id']][$checkout_day] = array();
				}
				$map[$stay['room_id']][$checkout_day][] = array_merge($stay, array('cell_status' => self::STATUS_CHECKOUT));
			}
		}

		// collapse same-day checkout+checkin entries into a single 'turnover' entry
		foreach ($map as $room_id => &$days) {
			foreach ($days as $day => &$entries) {
				$has_checkout = false;
				$has_checkin = false;
				foreach ($entries as $e) {
					if ($e['cell_status'] === self::STATUS_CHECKOUT) $has_checkout = true;
					if ($e['cell_status'] === self::STATUS_CHECKIN) $has_checkin = true;
				}
				if ($has_checkout && $has_checkin && count($entries) > 1) {
					foreach ($entries as &$e) {
						$e['is_turnover'] = true;
					}
					unset($e);
				}
			}
			unset($entries);
		}
		unset($days);

		return $map;
	}

	public function update_check_in_time($company_id, $booking_room_history_id, $time)
	{
		$row = $this->db->select('brh.booking_room_history_id, brh.check_in_date')
			->from('booking_block brh')
			->join('booking b', 'b.booking_id = brh.booking_id')
			->where(array('brh.booking_room_history_id' => $booking_room_history_id, 'b.company_id' => $company_id))
			->get()->row_array();

		if (!$row) {
			return false;
		}

		$date_part = substr($row['check_in_date'], 0, 10);
		$this->db->where('booking_room_history_id', $booking_room_history_id)
			->update('booking_block', array('check_in_date' => $date_part . ' ' . $time . ':00'));

		return true;
	}

	public function assign_room($company_id, $booking_room_history_id, $new_room_id)
	{
		$this->db->where('booking_room_history_id', $booking_room_history_id)
			->update('booking_block', array('room_id' => $new_room_id));
	}
}
