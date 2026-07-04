<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Shared chrome state for one company: active month/year, theme, language.
 * Simulator "today" is deliberately NOT stored here — it writes straight
 * through to company.selling_date, which already drives auto-checkout and
 * occupancy everywhere in core (see PLAN.md §4.7 / §5.4).
 */
class App_state_model extends CI_Model {

	public function get_or_create($company_id)
	{
		$row = $this->db->get_where('app_state', array('company_id' => $company_id))->row_array();

		if ($row) {
			return $row;
		}

		$now = time();
		$data = array(
			'company_id'   => $company_id,
			'active_month' => (int) date('n', $now),
			'active_year'  => (int) date('Y', $now),
			'theme'        => 'dark',
			'lang'         => 'english',
			'updated_at'   => date('Y-m-d H:i:s', $now),
		);
		$this->db->insert('app_state', $data);
		$data['id'] = $this->db->insert_id();

		return $data;
	}

	public function update($company_id, array $fields)
	{
		$this->get_or_create($company_id);
		$fields['updated_at'] = date('Y-m-d H:i:s');
		$this->db->where('company_id', $company_id)->update('app_state', $fields);
	}

	public function set_active_month($company_id, $month, $year)
	{
		$this->update($company_id, array(
			'active_month' => (int) $month,
			'active_year'  => (int) $year,
		));
	}

	public function set_theme($company_id, $theme)
	{
		$theme = in_array($theme, array('dark', 'light'), true) ? $theme : 'dark';
		$this->update($company_id, array('theme' => $theme));
	}

	public function set_lang($company_id, $lang)
	{
		$lang = in_array($lang, array('english', 'spanish'), true) ? $lang : 'english';
		$this->update($company_id, array('lang' => $lang));
	}

	/** Read osGrandHorizon's own business-date override — this IS the simulator date. */
	public function get_simulator_date($company_id)
	{
		$row = $this->db->select('selling_date')->get_where('company', array('company_id' => $company_id))->row_array();
		return $row ? $row['selling_date'] : date('Y-m-d');
	}

	public function set_simulator_date($company_id, $date)
	{
		$this->db->where('company_id', $company_id)->update('company', array('selling_date' => $date));
	}

	public function sync_to_month($company_id)
	{
		$sim_date = $this->get_simulator_date($company_id);
		$ts = strtotime($sim_date);
		$this->set_active_month($company_id, date('n', $ts), date('Y', $ts));
	}
}
