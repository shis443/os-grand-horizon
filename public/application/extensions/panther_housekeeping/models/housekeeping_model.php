<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Adapts miniCal's core room-status/housekeeping engine (room.status,
 * company housekeeping_auto_dirty/clean settings) plus a small extension
 * table (cleaning_requests) for manual per-booking "Cleaning Requested"
 * toggles raised from the reservation grid. A room counts as an open task
 * if it's Dirty (core auto-flagging) OR has an open cleaning_requests row.
 */
class Housekeeping_model extends CI_Model {

	public function open_count($company_id)
	{
		$sql = "SELECT COUNT(*) AS cnt FROM room r
			WHERE r.company_id = ? AND (
				r.status = 'Dirty'
				OR EXISTS (
					SELECT 1 FROM cleaning_requests cr
					WHERE cr.room_id = r.room_id AND cr.company_id = ? AND cr.status = 'requested'
				)
			)";
		$row = $this->db->query($sql, array($company_id, $company_id))->row_array();
		return (int) $row['cnt'];
	}

	/** Cards for the cleaning schedule: room, current/last guest, date, status. */
	public function get_open_tasks($company_id, $simulator_date)
	{
		$sql = "SELECT
				r.room_id, r.room_name, r.status AS room_status,
				cr.id AS request_id, cr.request_date, cr.status AS request_status,
				b.booking_id, c.customer_name
			FROM room r
			LEFT JOIN cleaning_requests cr
				ON cr.room_id = r.room_id AND cr.company_id = r.company_id AND cr.status = 'requested'
			LEFT JOIN booking b ON b.booking_id = cr.booking_id
			LEFT JOIN customer c ON c.customer_id = b.booking_customer_id
			WHERE r.company_id = ? AND (r.status = 'Dirty' OR cr.id IS NOT NULL)
			ORDER BY r.room_name ASC";

		return $this->db->query($sql, array($company_id))->result_array();
	}

	public function request_cleaning($company_id, $room_id, $booking_id, $date)
	{
		$existing = $this->db->where(array(
			'company_id' => $company_id,
			'room_id'    => $room_id,
			'request_date' => $date,
			'status'     => 'requested',
		))->get('cleaning_requests')->row_array();

		if ($existing) {
			return $existing['id'];
		}

		$this->db->insert('cleaning_requests', array(
			'company_id'   => $company_id,
			'booking_id'   => $booking_id,
			'room_id'      => $room_id,
			'request_date' => $date,
			'status'       => 'requested',
			'requested_at' => date('Y-m-d H:i:s'),
		));

		return $this->db->insert_id();
	}

	public function clear_request_for_room($company_id, $room_id)
	{
		$this->db->where(array('company_id' => $company_id, 'room_id' => $room_id, 'status' => 'requested'))
			->update('cleaning_requests', array('status' => 'cleared', 'cleared_at' => date('Y-m-d H:i:s')));
	}

	public function is_cleaning_requested($company_id, $room_id, $date)
	{
		$row = $this->db->where(array(
			'company_id' => $company_id, 'room_id' => $room_id,
			'request_date' => $date, 'status' => 'requested',
		))->get('cleaning_requests')->row_array();

		return (bool) $row;
	}

	public function mark_clean($company_id, $room_id)
	{
		$this->db->where(array('room_id' => $room_id, 'company_id' => $company_id))
			->update('room', array('status' => 'Clean'));
		$this->clear_request_for_room($company_id, $room_id);
	}
}
