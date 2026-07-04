<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Schema for panther_channel (Channex OTA ingestion). Reuses existing
 * ota_manager/ota_properties/ota_x_company/ota_room_types/ota_rate_plans/
 * ota_bookings/otas tables as-is (see PLAN_CHANNEL.md §1/§4) — these four
 * tables are the only genuinely new ones needed.
 */
class Migration_panther_channel extends CI_Migration {

	public function up() {

		## Create Table webhook_events
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'event_type' => array(
				'type' => 'VARCHAR',
				'constraint' => 40,
				'null' => FALSE,
			),
			'channex_booking_id' => array(
				'type' => 'VARCHAR',
				'constraint' => 64,
				'null' => TRUE,
			),
			'revision_id' => array(
				'type' => 'VARCHAR',
				'constraint' => 64,
				'null' => TRUE,
			),
			'secret_header_valid' => array(
				'type' => 'TINYINT',
				'constraint' => 1,
				'null' => FALSE,
				'default' => 0,
			),
			'processed' => array(
				'type' => 'TINYINT',
				'constraint' => 1,
				'null' => FALSE,
				'default' => 0,
			),
			'received_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key(array('channex_booking_id', 'revision_id'));
		$this->dbforge->create_table('webhook_events', TRUE);

		## Create Table channel_reservations
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'local_booking_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'channex_booking_id' => array(
				'type' => 'VARCHAR',
				'constraint' => 64,
				'null' => FALSE,
			),
			'ota_reservation_code' => array(
				'type' => 'VARCHAR',
				'constraint' => 100,
				'null' => TRUE,
			),
			'channel_code' => array(
				'type' => 'VARCHAR',
				'constraint' => 40,
				'null' => TRUE,
				'comment' => 'e.g. booking_dot_com, airbnb',
			),
			'status' => array(
				'type' => 'VARCHAR',
				'constraint' => 20,
				'null' => FALSE,
				'comment' => 'new|modified|cancelled (Channex status values)',
			),
			'guest_name' => array(
				'type' => 'VARCHAR',
				'constraint' => 191,
				'null' => TRUE,
			),
			'guest_email_alias' => array(
				'type' => 'VARCHAR',
				'constraint' => 191,
				'null' => TRUE,
			),
			'occupancy_json' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => TRUE,
			),
			'arrival_date' => array(
				'type' => 'DATE',
				'null' => TRUE,
			),
			'departure_date' => array(
				'type' => 'DATE',
				'null' => TRUE,
			),
			'gross_amount_cents' => array(
				'type' => 'INT',
				'constraint' => 11,
				'null' => TRUE,
			),
			'payout_cents' => array(
				'type' => 'INT',
				'constraint' => 11,
				'null' => TRUE,
			),
			'currency' => array(
				'type' => 'VARCHAR',
				'constraint' => 3,
				'null' => TRUE,
			),
			'raw_payload_json' => array(
				'type' => 'MEDIUMTEXT',
				'null' => TRUE,
				'comment' => 'card/guarantee fields stripped before storage',
			),
			'received_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
			'updated_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('channex_booking_id');
		$this->dbforge->add_key('local_booking_id');
		$this->dbforge->create_table('channel_reservations', TRUE);

		## Create Table channel_push_queue
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'room_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'date_from' => array(
				'type' => 'DATE',
				'null' => FALSE,
			),
			'date_to' => array(
				'type' => 'DATE',
				'null' => FALSE,
			),
			'reason' => array(
				'type' => 'VARCHAR',
				'constraint' => 60,
				'null' => TRUE,
			),
			'status' => array(
				'type' => 'VARCHAR',
				'constraint' => 10,
				'null' => FALSE,
				'default' => 'pending',
				'comment' => 'pending|sent|failed',
			),
			'attempts' => array(
				'type' => 'INT',
				'constraint' => 11,
				'null' => FALSE,
				'default' => 0,
			),
			'last_error' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => TRUE,
			),
			'scheduled_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
			'sent_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
			'created_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key(array('status', 'room_id'));
		$this->dbforge->create_table('channel_push_queue', TRUE);

		## Create Table channel_sync_log
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'direction' => array(
				'type' => 'VARCHAR',
				'constraint' => 3,
				'null' => FALSE,
				'comment' => 'in|out',
			),
			'operation' => array(
				'type' => 'VARCHAR',
				'constraint' => 60,
				'null' => FALSE,
				'comment' => 'e.g. test_credentials, push_availability, pull_feed',
			),
			'status' => array(
				'type' => 'VARCHAR',
				'constraint' => 20,
				'null' => FALSE,
				'comment' => 'success|failed',
			),
			'error' => array(
				'type' => 'VARCHAR',
				'constraint' => 500,
				'null' => TRUE,
			),
			'payload_json' => array(
				'type' => 'MEDIUMTEXT',
				'null' => TRUE,
				'comment' => 'secrets/card fields always stripped before storage',
			),
			'created_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('created_at');
		$this->dbforge->create_table('channel_sync_log', TRUE);
	}

	public function down() {
		$this->dbforge->drop_table('webhook_events', TRUE);
		$this->dbforge->drop_table('channel_reservations', TRUE);
		$this->dbforge->drop_table('channel_push_queue', TRUE);
		$this->dbforge->drop_table('channel_sync_log', TRUE);
	}
}
