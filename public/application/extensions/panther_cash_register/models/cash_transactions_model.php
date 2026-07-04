<?php defined('BASEPATH') OR exit('No direct script access allowed');

class Cash_transactions_model extends CI_Model {

	public function add($company_id, $type, $amount_eur_cents, $date, $customer, $category, $handled_by, $notes, $source_surcharge_id = null)
	{
		$this->db->insert('cash_transactions', array(
			'company_id'          => $company_id,
			'type'                => $type === 'out' ? 'out' : 'in',
			'amount_eur'          => (int) $amount_eur_cents,
			'date'                => $date,
			'customer'            => $customer,
			'category'            => $category,
			'handled_by'          => $handled_by,
			'notes'               => $notes,
			'source_surcharge_id' => $source_surcharge_id ?: null,
			'created_at'          => date('Y-m-d H:i:s'),
		));

		return $this->db->insert_id();
	}

	public function get_all($company_id, $search = null, $type = null)
	{
		$this->db->where('company_id', $company_id);

		if ($type === 'in' || $type === 'out') {
			$this->db->where('type', $type);
		}

		if ($search) {
			$this->db->group_start()
				->like('customer', $search)
				->or_like('category', $search)
				->or_like('notes', $search)
				->group_end();
		}

		return $this->db->order_by('date', 'DESC')->order_by('id', 'DESC')->get('cash_transactions')->result_array();
	}

	public function totals($company_id)
	{
		$in = $this->db->select_sum('amount_eur')->where(array('company_id' => $company_id, 'type' => 'in'))
			->get('cash_transactions')->row_array();
		$out = $this->db->select_sum('amount_eur')->where(array('company_id' => $company_id, 'type' => 'out'))
			->get('cash_transactions')->row_array();

		$in_cents = (int) ($in['amount_eur'] ?: 0);
		$out_cents = (int) ($out['amount_eur'] ?: 0);

		return array(
			'in_cents'      => $in_cents,
			'out_cents'     => $out_cents,
			'balance_cents' => $in_cents - $out_cents,
		);
	}
}
