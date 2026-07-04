<?php defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('panther_eur')) {
	/** Format integer cents as a EUR display string, e.g. 1050 -> "€10.50" */
	function panther_eur($cents)
	{
		return '€' . number_format(((int) $cents) / 100, 2);
	}
}

if (!function_exists('panther_booking_source_label')) {
	/**
	 * booking.source is an int: 0/negative values index into the
	 * COMMON_BOOKING_SOURCES map (config/constants.php); positive values are
	 * a company's own custom booking_source.id row. Falls back to 'Direct'.
	 */
	function panther_booking_source_label($source, $db = null)
	{
		$common = json_decode(COMMON_BOOKING_SOURCES, true);

		if (isset($common[$source])) {
			return $common[$source];
		}

		if ($db && $source > 0) {
			$row = $db->select('name')->get_where('booking_source', array('id' => $source))->row_array();
			if ($row) {
				return $row['name'];
			}
		}

		return 'Direct';
	}
}
