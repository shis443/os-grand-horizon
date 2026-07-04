<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Queued, throttled availability/restriction push jobs (PLAN_CHANNEL.md §7).
 * Every local mutation that can affect saleable nights enqueues a row here
 * instead of calling the Channex API inline — the cron_push_queue worker
 * drains this on a short interval, coalescing by room to respect Channex's
 * rate limits (10 req/min/property for each of availability/restrictions).
 */
class Channel_push_queue_model extends CI_Model {

	public function enqueue($room_id, $date_from, $date_to, $reason)
	{
		$this->db->insert('channel_push_queue', array(
			'room_id'      => $room_id,
			'date_from'    => $date_from,
			'date_to'      => $date_to,
			'reason'       => $reason,
			'status'       => 'pending',
			'scheduled_at' => date('Y-m-d H:i:s'),
			'created_at'   => date('Y-m-d H:i:s'),
		));

		return $this->db->insert_id();
	}

	/** Coalesced: one {room_id, min(date_from), max(date_to)} per room among pending rows. */
	public function get_pending_coalesced()
	{
		$sql = "SELECT room_id, MIN(date_from) AS date_from, MAX(date_to) AS date_to,
					GROUP_CONCAT(id) AS queue_ids
				FROM channel_push_queue
				WHERE status = 'pending'
				GROUP BY room_id";

		return $this->db->query($sql)->result_array();
	}

	public function mark_sent($queue_ids_csv)
	{
		$ids = explode(',', $queue_ids_csv);
		$this->db->where_in('id', $ids)->update('channel_push_queue', array(
			'status'  => 'sent',
			'sent_at' => date('Y-m-d H:i:s'),
		));
	}

	public function mark_failed($queue_ids_csv, $error)
	{
		$ids = explode(',', $queue_ids_csv);
		$this->db->where_in('id', $ids)
			->set('attempts', 'attempts + 1', false)
			->set('status', 'failed')
			->set('last_error', substr($error, 0, 255))
			->update('channel_push_queue');
	}

	public function get_failed($limit = 50)
	{
		return $this->db->where('status', 'failed')->order_by('id', 'DESC')->limit($limit)
			->get('channel_push_queue')->result_array();
	}

	public function count_pending()
	{
		return (int) $this->db->where('status', 'pending')->count_all_results('channel_push_queue');
	}
}
