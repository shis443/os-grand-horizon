<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Schema for the Sea Panther Reservas extensions (panther_shell, panther_grid,
 * panther_housekeeping, panther_room_status, panther_surcharges,
 * panther_cash_register, panther_audit_log). None of these alter core tables;
 * all FKs reference core tables (booking, room, users, company) for context
 * only, enforced at the application layer since CI's dbforge on MyISAM/InnoDB
 * mixed installs can vary — we keep referential integrity in the models.
 */
class Migration_panther_extensions extends CI_Migration {

	public function up() {

		## Create Table app_state (panther_shell)
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'active_month' => array(
				'type' => 'TINYINT',
				'constraint' => 2,
				'null' => FALSE,
			),
			'active_year' => array(
				'type' => 'SMALLINT',
				'constraint' => 4,
				'null' => FALSE,
			),
			'theme' => array(
				'type' => 'VARCHAR',
				'constraint' => 10,
				'null' => FALSE,
				'default' => 'dark',
			),
			'lang' => array(
				'type' => 'VARCHAR',
				'constraint' => 10,
				'null' => FALSE,
				'default' => 'english',
			),
			'updated_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->create_table('app_state', TRUE);

		## Create Table cleaning_requests (panther_housekeeping, written by panther_grid)
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'booking_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'room_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'request_date' => array(
				'type' => 'DATE',
				'null' => FALSE,
			),
			'status' => array(
				'type' => 'VARCHAR',
				'constraint' => 20,
				'null' => FALSE,
				'default' => 'requested',
			),
			'requested_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
			'cleared_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->add_key(array('room_id', 'request_date'));
		$this->dbforge->create_table('cleaning_requests', TRUE);

		## Create Table surcharges (panther_surcharges)
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'booking_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'room' => array(
				'type' => 'VARCHAR',
				'constraint' => 56,
				'null' => FALSE,
			),
			'guest_name' => array(
				'type' => 'VARCHAR',
				'constraint' => 128,
				'null' => FALSE,
			),
			'date' => array(
				'type' => 'DATE',
				'null' => FALSE,
			),
			'amount_eur' => array(
				'type' => 'INT',
				'constraint' => 11,
				'null' => FALSE,
				'comment' => 'integer cents',
			),
			'reason' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => TRUE,
			),
			'cleared_bool' => array(
				'type' => 'TINYINT',
				'constraint' => 1,
				'null' => FALSE,
				'default' => 0,
			),
			'created_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
			'cleared_at' => array(
				'type' => 'DATETIME',
				'null' => TRUE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->add_key('booking_id');
		$this->dbforge->create_table('surcharges', TRUE);

		## Create Table cash_transactions (panther_cash_register)
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'type' => array(
				'type' => 'VARCHAR',
				'constraint' => 3,
				'null' => FALSE,
				'comment' => "'in' or 'out'",
			),
			'amount_eur' => array(
				'type' => 'INT',
				'constraint' => 11,
				'null' => FALSE,
				'comment' => 'integer cents',
			),
			'date' => array(
				'type' => 'DATE',
				'null' => FALSE,
			),
			'customer' => array(
				'type' => 'VARCHAR',
				'constraint' => 128,
				'null' => TRUE,
			),
			'category' => array(
				'type' => 'VARCHAR',
				'constraint' => 64,
				'null' => FALSE,
			),
			'handled_by' => array(
				'type' => 'VARCHAR',
				'constraint' => 128,
				'null' => TRUE,
			),
			'notes' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => TRUE,
			),
			'source_surcharge_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'created_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->add_key('source_surcharge_id');
		$this->dbforge->create_table('cash_transactions', TRUE);

		## Create Table audit_log (panther_audit_log) — append-only, no update()/delete() in the model
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
				'auto_increment' => TRUE
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'type' => array(
				'type' => 'VARCHAR',
				'constraint' => 40,
				'null' => FALSE,
			),
			'room' => array(
				'type' => 'VARCHAR',
				'constraint' => 56,
				'null' => TRUE,
			),
			'user_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'utc_timestamp' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
			'message' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->create_table('audit_log', TRUE);

		## Create Table audit_log_archive — same shape, destination for "Clear History"
		$this->dbforge->add_field(array(
			'id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'company_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => FALSE,
			),
			'type' => array(
				'type' => 'VARCHAR',
				'constraint' => 40,
				'null' => FALSE,
			),
			'room' => array(
				'type' => 'VARCHAR',
				'constraint' => 56,
				'null' => TRUE,
			),
			'user_id' => array(
				'type' => 'BIGINT',
				'constraint' => 20,
				'unsigned' => TRUE,
				'null' => TRUE,
			),
			'utc_timestamp' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
			'message' => array(
				'type' => 'VARCHAR',
				'constraint' => 255,
				'null' => FALSE,
			),
			'archived_at' => array(
				'type' => 'DATETIME',
				'null' => FALSE,
			),
		));
		$this->dbforge->add_key('id', true);
		$this->dbforge->add_key('company_id');
		$this->dbforge->create_table('audit_log_archive', TRUE);
	}

	public function down() {
		$this->dbforge->drop_table('app_state', TRUE);
		$this->dbforge->drop_table('cleaning_requests', TRUE);
		$this->dbforge->drop_table('surcharges', TRUE);
		$this->dbforge->drop_table('cash_transactions', TRUE);
		$this->dbforge->drop_table('audit_log', TRUE);
		$this->dbforge->drop_table('audit_log_archive', TRUE);
	}
}
