<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Base controller shared by every panther_* extension. Additive-only core
 * file (does not modify any existing core class) — provides the common
 * chrome (nav/theme/simulator date/month/badges) so each extension's
 * controller only has to supply its own content view. See PLAN.md §1.
 *
 * NOTE: this app's MY_Loader (application/core/MY_Loader.php) extends plain
 * CI_Loader, not MX_Loader — only routing goes through wiredesignz MX here.
 * Cross-extension model/view loading therefore uses the standard CI
 * add_package_path() mechanism (already used elsewhere in MY_Loader::spark())
 * rather than MX's 'module/Model' loader syntax, which this app does not wire up.
 */
class Panther_controller extends MY_Controller {

	protected $panther_modules = array(
		'panther_shell', 'panther_audit_log', 'panther_surcharges',
		'panther_cash_register', 'panther_grid', 'panther_housekeeping',
		'panther_room_status',
	);

	public function __construct()
	{
		parent::__construct();

		foreach ($this->panther_modules as $module) {
			$this->load->add_package_path(APPPATH . 'extensions/' . $module . '/');
		}

		$this->load->helper('panther_money');
		$this->load->model('app_state_model', 'panther_state');
		$this->load->model('audit_log_model', 'panther_audit');
	}

	protected function panther_chrome_data($active)
	{
		$state = $this->panther_state->get_or_create($this->company_id);

		return array(
			'panther_active'       => $active,
			'panther_theme'        => $state['theme'],
			'panther_lang'         => $state['lang'],
			'panther_sim_date'     => $this->panther_state->get_simulator_date($this->company_id),
			'panther_active_month' => $state['active_month'],
			'panther_active_year'  => $state['active_year'],
			'panther_company_name' => $this->company_name,
			'panther_badges'       => $this->panther_badges(),
		);
	}

	protected function panther_badges()
	{
		$badges = array();

		$this->load->model('housekeeping_model', 'ph_badge');
		$badges['housekeeping_count'] = $this->ph_badge->open_count($this->company_id);

		$this->load->model('surcharges_model', 'ps_badge');
		$badges['surcharges_total_label'] = $this->ps_badge->uncleared_total_label($this->company_id);

		return $badges;
	}

	/** Render a panther_* view sandwiched in the shared chrome. */
	protected function panther_render($view, $data, $active)
	{
		$data = array_merge($this->panther_chrome_data($active), $data);
		$this->load->view('chrome_top', $data);
		$this->load->view($view, $data);
		$this->load->view('chrome_bottom', $data);
	}
}
