<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Shell extends Panther_controller {

	public function index()
	{
		$this->panther_render('settings', array(), 'shell');
	}

	public function set_theme()
	{
		$this->panther_state->set_theme($this->company_id, $this->input->post('theme'));
		echo json_encode(array('success' => true));
	}

	public function set_lang()
	{
		$lang = $this->input->post('lang') === 'spanish' ? 'spanish' : 'english';
		$this->panther_state->set_lang($this->company_id, $lang);
		$this->session->set_userdata('language', $lang);
		echo json_encode(array('success' => true));
	}

	public function set_simulator_date()
	{
		$date = $this->input->post('date');

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			echo json_encode(array('success' => false, 'message' => 'Invalid date format.'));
			return;
		}

		$this->panther_state->set_simulator_date($this->company_id, $date);
		$this->panther_audit->add($this->company_id, 'simulator_date_changed', null, $this->user_id,
			"Simulator date set to $date");

		echo json_encode(array('success' => true));
	}

	public function sync_to_month()
	{
		$this->panther_state->sync_to_month($this->company_id);
		$state = $this->panther_state->get_or_create($this->company_id);

		echo json_encode(array(
			'success'  => true,
			'redirect' => 'panther_grid/month/' . $state['active_year'] . '/' . $state['active_month'],
		));
	}

	/** Admin-only: truncate our 4 extension tables and reseed the demo July sheet. */
	public function reset_data()
	{
		if ($this->user_permission !== 'is_admin' && $this->user_permission !== 'is_owner' && !$this->is_super_admin) {
			show_404();
			return;
		}

		// Note: audit_log/audit_log_archive are deliberately NOT touched here —
		// hard-deleting them would violate the append-only guarantee. Use
		// "Clear History" in the Audit Log screen instead, which archives.
		$this->db->where('company_id', $this->company_id)->delete('surcharges');
		$this->db->where('company_id', $this->company_id)->delete('cash_transactions');
		$this->db->where('company_id', $this->company_id)->delete('cleaning_requests');
		$this->db->where('company_id', $this->company_id)->delete('booking_block');
		$this->db->where('company_id', $this->company_id)->delete('booking');
		$this->db->where('company_id', $this->company_id)->delete('customer');

		$this->load->helper('demo_seed');
		panther_seed_demo_july($this->company_id, $this->db);

		$this->panther_audit->add($this->company_id, 'reset_data', null, $this->user_id,
			'Admin reset demo data (surcharges/cash/cleaning tables + demo bookings, reseeded demo July sheet). Audit log left untouched.');

		echo json_encode(array('success' => true));
	}
}
