<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Housekeeping extends Panther_controller {

	public function index()
	{
		$this->load->model('housekeeping_model', 'hk');
		$sim_date = $this->panther_state->get_simulator_date($this->company_id);

		$this->panther_render('housekeeping_index', array(
			'tasks' => $this->hk->get_open_tasks($this->company_id, $sim_date),
		), 'housekeeping');
	}

	public function mark_clean($room_id)
	{
		$this->load->model('housekeeping_model', 'hk');
		$this->hk->mark_clean($this->company_id, $room_id);

		$this->load->model('Room_model');
		$room = $this->Room_model->get_room($room_id);

		$this->panther_audit->add($this->company_id, 'room_cleaned', $room ? $room['room_name'] : null, $this->user_id,
			'Room marked clean via housekeeping schedule.');

		echo json_encode(array('success' => true));
	}

	/** AJAX: nav badge count, polled by chrome. */
	public function badge_count()
	{
		$this->load->model('housekeeping_model', 'hk');
		echo json_encode(array('count' => $this->hk->open_count($this->company_id)));
	}
}
