<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Room_status extends Panther_controller {

	public function index()
	{
		$this->load->model('room_status_model', 'rs');
		$this->load->model('surcharges_model', 'sc');

		$date = $this->panther_state->get_simulator_date($this->company_id);

		$this->panther_render('room_status_index', array(
			'rooms'        => $this->rs->get_room_cards($this->company_id, $date),
			'arrivals'     => $this->rs->get_arrivals($this->company_id, $date),
			'stats'        => $this->rs->operational_stats($this->company_id, $date),
			'unpaid_count' => count($this->sc->get_all($this->company_id, null, true)),
			'sim_date'     => $date,
		), 'room_status');
	}

	public function check_in($booking_id)
	{
		$this->load->model('room_status_model', 'rs');
		$this->rs->check_in($this->company_id, $booking_id);

		$this->panther_audit->add($this->company_id, 'check_in', $this->input->post('room_name'), $this->user_id,
			'Guest checked in via Live Room Status.');

		echo json_encode(array('success' => true));
	}
}
