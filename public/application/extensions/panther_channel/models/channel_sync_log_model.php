<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Structured sync log for the admin "error feed" — see PLAN_CHANNEL.md §4 for
 * why this is a dedicated table rather than reusing ota_xml_logs/save_logs()
 * (that table's retention job has undocumented assumptions about its
 * request_type codes we don't want to disturb).
 */
class Channel_sync_log_model extends CI_Model {

	/** $payload is redacted before storage — never pass api keys/card data in here. */
	public function log($direction, $operation, $status, $error = null, $payload = null)
	{
		$this->db->insert('channel_sync_log', array(
			'direction'    => $direction === 'out' ? 'out' : 'in',
			'operation'    => $operation,
			'status'       => $status,
			'error'        => $error ? substr($error, 0, 500) : null,
			'payload_json' => $payload !== null ? json_encode($this->redact($payload)) : null,
			'created_at'   => date('Y-m-d H:i:s'),
		));

		return $this->db->insert_id();
	}

	public function get_recent($limit = 100, $status = null)
	{
		if ($status) {
			$this->db->where('status', $status);
		}
		return $this->db->order_by('id', 'DESC')->limit($limit)->get('channel_sync_log')->result_array();
	}

	public function get_failed_count()
	{
		return (int) $this->db->where('status', 'failed')->count_all_results('channel_sync_log');
	}

	/** Strip anything that looks like a secret/card field, defense in depth beyond §2d. */
	private function redact($data)
	{
		if (!is_array($data)) {
			return $data;
		}

		$redact_keys = array('api_key', 'user-api-key', 'card_number', 'cvv', 'password', 'guarantee', 'secret');

		foreach ($data as $key => $value) {
			if (is_string($key) && in_array(strtolower($key), $redact_keys, true)) {
				$data[$key] = '[redacted]';
			} elseif (is_array($value)) {
				$data[$key] = $this->redact($value);
			}
		}

		return $data;
	}
}
