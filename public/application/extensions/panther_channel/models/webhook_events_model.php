<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Receipt log for inbound webhooks. NOT the authoritative dedupe mechanism
 * (that's Channex's own booking_revisions/feed ack system, per PLAN_CHANNEL.md
 * §2b) — this exists so a webhook redelivery doesn't re-trigger a redundant
 * feed-pull, and so the admin UI has a "last webhook received" timestamp.
 */
class Webhook_events_model extends CI_Model {

	public function record($event_type, $channex_booking_id, $revision_id, $secret_header_valid)
	{
		$existing = $this->db->where(array(
			'channex_booking_id' => $channex_booking_id,
			'revision_id'        => $revision_id,
		))->get('webhook_events')->row_array();

		if ($existing) {
			return array('id' => $existing['id'], 'is_duplicate' => true);
		}

		$this->db->insert('webhook_events', array(
			'event_type'           => $event_type,
			'channex_booking_id'   => $channex_booking_id,
			'revision_id'          => $revision_id,
			'secret_header_valid'  => $secret_header_valid ? 1 : 0,
			'processed'            => 0,
			'received_at'          => date('Y-m-d H:i:s'),
		));

		return array('id' => $this->db->insert_id(), 'is_duplicate' => false);
	}

	public function mark_processed($id)
	{
		$this->db->where('id', $id)->update('webhook_events', array('processed' => 1));
	}

	public function get_last_received()
	{
		$row = $this->db->order_by('id', 'DESC')->limit(1)->get('webhook_events')->row_array();
		return $row ? $row['received_at'] : null;
	}
}
