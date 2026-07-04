<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Append-only audit trail. Deliberately exposes no update()/delete() method —
 * immutability is enforced by the absence of the capability, not a runtime
 * check. The only way to shorten the live log is archive_all(), which moves
 * every row to audit_log_archive (nothing is destroyed) and then logs that
 * very action as the first new entry, so the clear itself is permanently
 * auditable too.
 */
class Audit_log_model extends CI_Model {

	public function add($company_id, $type, $room, $user_id, $message)
	{
		$this->db->insert('audit_log', array(
			'company_id'    => $company_id,
			'type'          => $type,
			'room'          => $room,
			'user_id'       => $user_id,
			'utc_timestamp' => gmdate('Y-m-d H:i:s'),
			'message'       => $message,
		));
		return $this->db->insert_id();
	}

	public function get_all($company_id, $limit = 500)
	{
		return $this->db
			->where('company_id', $company_id)
			->order_by('id', 'DESC')
			->limit($limit)
			->get('audit_log')
			->result_array();
	}

	public function count_all($company_id)
	{
		return (int) $this->db->where('company_id', $company_id)->count_all_results('audit_log');
	}

	/** Archive every current row for this company, then log the clear action itself. */
	public function archive_all($company_id, $admin_user_id)
	{
		$rows = $this->db->where('company_id', $company_id)->get('audit_log')->result_array();

		if ($rows) {
			$now = gmdate('Y-m-d H:i:s');
			foreach ($rows as &$row) {
				$row['archived_at'] = $now;
			}
			unset($row);
			$this->db->insert_batch('audit_log_archive', $rows);
			$this->db->where('company_id', $company_id)->delete('audit_log');
		}

		$this->add($company_id, 'clear_history', null, $admin_user_id,
			sprintf('Admin cleared %d audit log entries (archived, not deleted).', count($rows)));
	}
}
