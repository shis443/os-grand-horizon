<?php defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/Panther_controller.php';

class Audit_log extends Panther_controller {

	public function index()
	{
		$this->load->model('User_model');
		$entries = $this->panther_audit->get_all($this->company_id);

		foreach ($entries as &$entry) {
			if ($entry['user_id']) {
				$u = $this->User_model->get_user_by_id($entry['user_id']);
				$name = $u ? trim($u['first_name'] . ' ' . $u['last_name']) : '';
				$entry['user_label'] = $name ?: ($u ? $u['email'] : ('#' . $entry['user_id']));
			} else {
				$entry['user_label'] = 'system';
			}
		}
		unset($entry);

		$this->panther_render('audit_log_index', array(
			'entries' => $entries,
			'is_admin' => ($this->user_permission === 'is_admin' || $this->user_permission === 'is_owner' || $this->is_super_admin),
		), 'audit_log');
	}

	public function clear()
	{
		if ($this->user_permission !== 'is_admin' && $this->user_permission !== 'is_owner' && !$this->is_super_admin) {
			show_404();
			return;
		}

		$this->panther_audit->archive_all($this->company_id, $this->user_id);
		echo json_encode(array('success' => true));
	}
}
