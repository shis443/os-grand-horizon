<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Fixes a drift introduced by the earlier Minical->OS Grand Horizon rebrand:
 * 001_create_base.php's TEXT was correctly updated to
 * osgrandhorizon_room_type_id/osgrandhorizon_rate_plan_id, but on any
 * database that already existed before that rebrand (i.e. this one),
 * dbforge's create_table(..., TRUE) is a no-op for an existing table — the
 * live columns were still named minical_room_type_id/minical_rate_plan_id
 * until this migration runs. On a genuinely fresh install (migrations
 * replayed from scratch), 001_create_base.php already creates the correct
 * column names, so the renames below are skipped automatically.
 */
class Migration_fix_channex_column_rebrand extends CI_Migration {

	public function up() {
		if ($this->_column_exists('ota_room_types', 'minical_room_type_id')) {
			$this->db->query("ALTER TABLE `ota_room_types` CHANGE `minical_room_type_id` `osgrandhorizon_room_type_id` BIGINT(20) NULL");
		}

		if ($this->_column_exists('ota_rate_plans', 'minical_rate_plan_id')) {
			$this->db->query("ALTER TABLE `ota_rate_plans` CHANGE `minical_rate_plan_id` `osgrandhorizon_rate_plan_id` BIGINT(20) NULL");
		}
	}

	public function down() {
		if ($this->_column_exists('ota_room_types', 'osgrandhorizon_room_type_id')) {
			$this->db->query("ALTER TABLE `ota_room_types` CHANGE `osgrandhorizon_room_type_id` `minical_room_type_id` BIGINT(20) NULL");
		}

		if ($this->_column_exists('ota_rate_plans', 'osgrandhorizon_rate_plan_id')) {
			$this->db->query("ALTER TABLE `ota_rate_plans` CHANGE `osgrandhorizon_rate_plan_id` `minical_rate_plan_id` BIGINT(20) NULL");
		}
	}

	private function _column_exists($table, $column) {
		$row = $this->db->query(
			"SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
			array($table, $column)
		)->row_array();

		return $row && (int) $row['cnt'] > 0;
	}
}
