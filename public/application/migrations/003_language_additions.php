<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Enables Italian (already shipped as a partial language pack but seeded
 * disabled) and adds Maltese (new pack: application/language/maltese/,
 * flag public/images/language_flags/maltese.png) to the language switcher.
 */
class Migration_language_additions extends CI_Migration {

	public function up() {
		$this->db->where('language_name', 'Italian')->update('language', array('is_enable' => 1));

		$exists = $this->db->where('language_name', 'Maltese')->get('language')->row_array();
		if (!$exists) {
			$this->db->insert('language', array(
				'language_name'   => 'Maltese',
				'is_default_lang' => 0,
				'is_enable'       => 1,
				'version'         => 1,
				'flag'            => 'maltese',
			));
		}
	}

	public function down() {
		$this->db->where('language_name', 'Italian')->update('language', array('is_enable' => 0));
		$this->db->where('language_name', 'Maltese')->delete('language');
	}
}
