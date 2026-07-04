<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Derives per-room occupancy from core tables (room, booking_room_history,
 * booking, customer) for a given date — no duplicated occupancy state, no
 * new tables. Reads the simulator date from panther_shell's write-through
 * to company.selling_date.
 */
class Room_status_model extends CI_Model {

	public function get_room_cards($company_id, $date)
	{
		$sql = "SELECT
				r.room_id, r.room_name, r.status AS housekeeping_status,
				b.booking_id, b.state, c.customer_name,
				brh.check_in_date, brh.check_out_date, b.source
			FROM room r
			LEFT JOIN booking_block brh
				ON brh.room_id = r.room_id
				AND DATE(brh.check_in_date) <= ? AND DATE(brh.check_out_date) > ?
			LEFT JOIN booking b ON b.booking_id = brh.booking_id AND b.state = " . INHOUSE . "
			LEFT JOIN customer c ON c.customer_id = b.booking_customer_id
			WHERE r.company_id = ?
			ORDER BY r.room_name ASC";

		return $this->db->query($sql, array($date, $date, $company_id))->result_array();
	}

	/** A room checking in today: RESERVATION booking whose stay covers $date, not yet INHOUSE. */
	public function get_arrivals($company_id, $date)
	{
		$sql = "SELECT r.room_id, r.room_name, b.booking_id, c.customer_name
			FROM room r
			JOIN booking_block brh
				ON brh.room_id = r.room_id
				AND DATE(brh.check_in_date) <= ? AND DATE(brh.check_out_date) > ?
			JOIN booking b ON b.booking_id = brh.booking_id AND b.state = " . RESERVATION . "
			LEFT JOIN customer c ON c.customer_id = b.booking_customer_id
			WHERE r.company_id = ?
			ORDER BY r.room_name ASC";

		return $this->db->query($sql, array($date, $date, $company_id))->result_array();
	}

	public function check_in($company_id, $booking_id)
	{
		$this->db->where(array('booking_id' => $booking_id, 'company_id' => $company_id))
			->update('booking', array('state' => INHOUSE));
	}

	public function operational_stats($company_id, $date)
	{
		$cards = $this->get_room_cards($company_id, $date);
		$occupied = 0;
		foreach ($cards as $c) {
			if ($c['booking_id']) {
				$occupied++;
			}
		}

		return array(
			'occupied' => $occupied,
			'vacant'   => count($cards) - $occupied,
			'total'    => count($cards),
		);
	}
}
