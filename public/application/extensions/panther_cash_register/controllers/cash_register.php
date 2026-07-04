<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Cash_register extends Panther_controller {

	public function index()
	{
		$this->load->model('cash_transactions_model', 'ct');
		$this->load->model('surcharges_model', 'sc');

		$search = $this->input->get('q');
		$type   = $this->input->get('type');

		$this->panther_render('cash_register_index', array(
			'transactions'    => $this->ct->get_all($this->company_id, $search, $type),
			'totals'          => $this->ct->totals($this->company_id),
			'open_surcharges' => $this->sc->get_all($this->company_id, null, true),
			'search'          => $search,
			'type'            => $type,
		), 'cash_register');
	}

	public function log_income()
	{
		$this->_log('in');
	}

	public function log_expense()
	{
		$this->_log('out');
	}

	private function _log($type)
	{
		$this->load->model('cash_transactions_model', 'ct');

		$amount = (float) $this->input->post('amount_eur');
		$date   = $this->input->post('date');

		if ($amount <= 0 || !$date) {
			echo json_encode(array('success' => false, 'message' => 'Date and a positive amount are required.'));
			return;
		}

		$cents = (int) round($amount * 100);
		$this->ct->add(
			$this->company_id, $type, $cents, $date,
			$this->input->post('customer'), $this->input->post('category'),
			$this->input->post('handled_by'), $this->input->post('notes')
		);

		$this->panther_audit->add($this->company_id, $type === 'in' ? 'cash_in' : 'cash_out', null, $this->user_id,
			sprintf('%s %s (%s)', $type === 'in' ? 'Logged income' : 'Logged expense', panther_eur($cents), $this->input->post('category')));

		echo json_encode(array('success' => true));
	}

	/** Collect an open surcharge as cash income and clear it, in one action. */
	public function collect($surcharge_id)
	{
		$this->load->model('surcharges_model', 'sc');
		$this->load->model('cash_transactions_model', 'ct');

		$surcharge = $this->sc->get($this->company_id, $surcharge_id);

		if (!$surcharge || $surcharge['cleared_bool']) {
			echo json_encode(array('success' => false, 'message' => 'Surcharge not found or already cleared.'));
			return;
		}

		$this->ct->add(
			$this->company_id, 'in', $surcharge['amount_eur'], date('Y-m-d'),
			$surcharge['guest_name'], 'Surcharge Collection', $this->first_name . ' ' . $this->last_name,
			'Collected for ' . $surcharge['room'] . ' surcharge #' . $surcharge['id'], $surcharge['id']
		);
		$this->sc->clear($this->company_id, $surcharge_id);

		$this->panther_audit->add($this->company_id, 'surcharge_collected', $surcharge['room'], $this->user_id,
			sprintf('Collected %s cash for surcharge on %s', panther_eur($surcharge['amount_eur']), $surcharge['guest_name']));

		echo json_encode(array('success' => true));
	}
}
