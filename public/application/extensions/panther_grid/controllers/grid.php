<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Grid extends Panther_controller {

	public function month($year = null, $month = null)
	{
		$state = $this->panther_state->get_or_create($this->company_id);
		$year  = $year ?: $state['active_year'];
		$month = $month ?: $state['active_month'];

		$this->panther_state->set_active_month($this->company_id, $month, $year);

		$this->load->model('grid_model', 'grid');
		$this->load->model('housekeeping_model', 'hk');
		$this->load->model('surcharges_model', 'sc');

		$rooms = $this->grid->get_rooms($this->company_id);
		$cell_map = $this->grid->build_cell_map($this->company_id, $year, $month);
		$days_in_month = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
		$surcharge_totals = $this->sc->get_uncleared_totals_by_booking($this->company_id);

		$this->panther_render('grid_index', array(
			'rooms'            => $rooms,
			'cell_map'         => $cell_map,
			'days_in_month'    => $days_in_month,
			'year'             => (int) $year,
			'month'            => (int) $month,
			'search'           => $this->input->get('q'),
			'surcharge_totals' => $surcharge_totals,
		), 'grid');
	}

	public function update_check_in_time()
	{
		$this->load->model('grid_model', 'grid');
		$ok = $this->grid->update_check_in_time(
			$this->company_id,
			$this->input->post('booking_room_history_id'),
			$this->input->post('time')
		);

		if ($ok) {
			$this->panther_audit->add($this->company_id, 'check_in_time_changed', $this->input->post('room_name'), $this->user_id,
				'Check-in time set to ' . $this->input->post('time'));
		}

		echo json_encode(array('success' => (bool) $ok));
	}

	public function assign_room()
	{
		$this->load->model('grid_model', 'grid');
		$this->grid->assign_room($this->company_id, $this->input->post('booking_room_history_id'), $this->input->post('room_id'));

		$this->panther_audit->add($this->company_id, 'room_reassigned', $this->input->post('room_name'), $this->user_id,
			'Booking reassigned to a different room from the grid.');

		echo json_encode(array('success' => true));
	}

	public function toggle_cleaning()
	{
		$this->load->model('housekeeping_model', 'hk');

		$room_id    = $this->input->post('room_id');
		$booking_id = $this->input->post('booking_id');
		$date       = $this->input->post('date');

		if ($this->hk->is_cleaning_requested($this->company_id, $room_id, $date)) {
			$this->hk->clear_request_for_room($this->company_id, $room_id);
			$requested = false;
		} else {
			$this->hk->request_cleaning($this->company_id, $room_id, $booking_id, $date);
			$requested = true;
		}

		$this->panther_audit->add($this->company_id, $requested ? 'cleaning_requested' : 'cleaning_request_cleared',
			$this->input->post('room_name'), $this->user_id, 'Cleaning flag toggled from the reservation grid.');

		echo json_encode(array('success' => true, 'requested' => $requested));
	}
}
