<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Surcharges extends Panther_controller {

	public function index()
	{
		$this->load->model('surcharges_model', 'sc');
		$search = $this->input->get('q');

		$this->panther_render('surcharges_index', array(
			'surcharges' => $this->sc->get_all($this->company_id, $search),
			'search'     => $search,
			'total_open' => $this->sc->uncleared_total_label($this->company_id),
		), 'surcharges');
	}

	/** Called both from this extension's own "+Add" form and from panther_grid's cell modal. */
	public function log()
	{
		$this->load->model('surcharges_model', 'sc');

		$booking_id = $this->input->post('booking_id');
		$room       = $this->input->post('room');
		$guest_name = $this->input->post('guest_name');
		$date       = $this->input->post('date');
		$amount     = (float) $this->input->post('amount_eur');
		$reason     = $this->input->post('reason');

		if ($amount <= 0 || !$room || !$date) {
			echo json_encode(array('success' => false, 'message' => 'Room, date, and a positive amount are required.'));
			return;
		}

		$cents = (int) round($amount * 100);
		$id = $this->sc->add($this->company_id, $booking_id, $room, $guest_name, $date, $cents, $reason);

		$this->panther_audit->add($this->company_id, 'extra_fee_logged', $room, $this->user_id,
			sprintf('Logged %s surcharge for %s (%s)', panther_eur($cents), $guest_name ?: 'guest', $reason ?: 'no reason given'));

		echo json_encode(array('success' => true, 'id' => $id, 'amount_label' => panther_eur($cents)));
	}

	public function clear($id)
	{
		$this->load->model('surcharges_model', 'sc');
		$row = $this->sc->get($this->company_id, $id);

		if (!$row) {
			show_404();
			return;
		}

		$this->sc->clear($this->company_id, $id);
		$this->panther_audit->add($this->company_id, 'surcharge_cleared', $row['room'], $this->user_id,
			sprintf('Cleared %s surcharge for %s', panther_eur($row['amount_eur']), $row['guest_name']));

		echo json_encode(array('success' => true));
	}
}
