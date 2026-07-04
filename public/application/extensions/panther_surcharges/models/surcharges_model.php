<?php defined('BASEPATH') OR exit('No direct script access allowed');

/** Ad-hoc per-booking charges ledger. Money stored as integer cents throughout. */
class Surcharges_model extends CI_Model {

	public function add($company_id, $booking_id, $room, $guest_name, $date, $amount_eur_cents, $reason)
	{
		$this->db->insert('surcharges', array(
			'company_id'  => $company_id,
			'booking_id'  => $booking_id ?: null,
			'room'        => $room,
			'guest_name'  => $guest_name,
			'date'        => $date,
			'amount_eur'  => (int) $amount_eur_cents,
			'reason'      => $reason,
			'cleared_bool'=> 0,
			'created_at'  => date('Y-m-d H:i:s'),
		));

		return $this->db->insert_id();
	}

	public function get_all($company_id, $search = null, $only_open = false)
	{
		$this->db->where('company_id', $company_id);

		if ($only_open) {
			$this->db->where('cleared_bool', 0);
		}

		if ($search) {
			$this->db->group_start()
				->like('guest_name', $search)
				->or_like('room', $search)
				->group_end();
		}

		return $this->db->order_by('date', 'DESC')->order_by('id', 'DESC')->get('surcharges')->result_array();
	}

	public function get($company_id, $id)
	{
		return $this->db->where(array('company_id' => $company_id, 'id' => $id))->get('surcharges')->row_array();
	}

	public function clear($company_id, $id)
	{
		$this->db->where(array('company_id' => $company_id, 'id' => $id))
			->update('surcharges', array('cleared_bool' => 1, 'cleared_at' => date('Y-m-d H:i:s')));
	}

	public function uncleared_total_cents($company_id)
	{
		$row = $this->db->select_sum('amount_eur')
			->where(array('company_id' => $company_id, 'cleared_bool' => 0))
			->get('surcharges')->row_array();

		return (int) ($row['amount_eur'] ?: 0);
	}

	public function uncleared_total_label($company_id)
	{
		return panther_eur($this->uncleared_total_cents($company_id));
	}

	/** booking_id => uncleared total cents, for badge rendering on the grid. */
	public function get_uncleared_totals_by_booking($company_id)
	{
		$rows = $this->db->select('booking_id, SUM(amount_eur) AS total')
			->where(array('company_id' => $company_id, 'cleared_bool' => 0))
			->where('booking_id IS NOT NULL')
			->group_by('booking_id')
			->get('surcharges')->result_array();

		$totals = array();
		foreach ($rows as $row) {
			$totals[$row['booking_id']] = (int) $row['total'];
		}

		return $totals;
	}

	public function get_open_for_booking($company_id, $booking_id)
	{
		return $this->db->where(array('company_id' => $company_id, 'booking_id' => $booking_id, 'cleared_bool' => 0))
			->get('surcharges')->result_array();
	}
}
