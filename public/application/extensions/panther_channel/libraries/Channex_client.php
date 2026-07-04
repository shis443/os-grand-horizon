<?php defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Thin HTTP client for the Channex REST API (https://docs.channex.io).
 * Auth: single `user-api-key` header (Channex has no OAuth/refresh flow).
 * Credentials come from .env only — never persisted to DB, never logged.
 *
 * Endpoints used (all confirmed against the docs, not assumed — see
 * PLAN_CHANNEL.md §2/§9):
 *   GET  /properties/:id                 - test connection / property info
 *   GET  /booking_revisions/feed          - pull pending (unacked) bookings
 *   POST /booking_revisions/:id/ack       - acknowledge a processed revision
 *   GET  /bookings/:id                    - fetch a single booking (fallback)
 *   POST /availability                    - push room_type availability
 *   POST /restrictions                    - push rate_plan restrictions/rates
 *   POST /webhooks                        - register our callback + shared-secret header
 */
class Channex_client {

	private $base_url;
	private $api_key;
	private $property_id;

	public function __construct()
	{
		$this->base_url = rtrim(getenv('CHANNEX_BASE_URL'), '/');
		$this->api_key = getenv('CHANNEX_API_KEY');
		$this->property_id = getenv('CHANNEX_PROPERTY_ID');
	}

	public function is_configured()
	{
		return (bool) ($this->base_url && $this->api_key && $this->property_id);
	}

	public function get_property()
	{
		return $this->request('GET', '/properties/' . $this->property_id);
	}

	public function get_revision_feed()
	{
		return $this->request('GET', '/booking_revisions/feed', array('property_id' => $this->property_id));
	}

	public function ack_revision($revision_id)
	{
		return $this->request('POST', '/booking_revisions/' . $revision_id . '/ack');
	}

	public function get_booking($channex_booking_id)
	{
		return $this->request('GET', '/bookings/' . $channex_booking_id);
	}

	/** $values: array of {room_type_id, date_from, date_to, availability} */
	public function push_availability($values)
	{
		foreach ($values as &$v) { $v['property_id'] = $this->property_id; }
		unset($v);
		return $this->request('POST', '/availability', null, array('values' => $values));
	}

	/** $values: array of {rate_plan_id, date_from, date_to, rate?, stop_sell?, min_stay?, ...} */
	public function push_restrictions($values)
	{
		foreach ($values as &$v) { $v['property_id'] = $this->property_id; }
		unset($v);
		return $this->request('POST', '/restrictions', null, array('values' => $values));
	}

	public function register_webhook($callback_url, $secret_header_name, $secret_value)
	{
		return $this->request('POST', '/webhooks', null, array('webhook' => array(
			'callback_url' => $callback_url,
			'event_mask'   => 'booking_new,booking_modification,booking_cancellation',
			'property_id'  => $this->property_id,
			'is_global'    => false,
			'headers'      => array($secret_header_name => $secret_value),
			'is_active'    => true,
			'send_data'    => true,
		)));
	}

	private function request($method, $path, $query = null, $body = null)
	{
		if (!$this->is_configured()) {
			return array('success' => false, 'http_code' => 0, 'error' => 'Channex is not configured (.env)', 'body' => null);
		}

		$url = $this->base_url . $path;
		if ($query) {
			$url .= '?' . http_build_query($query);
		}

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'user-api-key: ' . $this->api_key,
			'Content-Type: application/json',
			'Accept: application/json',
		));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		if ($body !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		$response = curl_exec($ch);
		$http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_error = curl_error($ch);
		curl_close($ch);

		if ($curl_error) {
			return array('success' => false, 'http_code' => 0, 'error' => $curl_error, 'body' => null);
		}

		$decoded = json_decode($response, true);
		$success = ($http_code >= 200 && $http_code < 300);

		return array(
			'success'   => $success,
			'http_code' => $http_code,
			'error'     => $success ? null : $this->extract_error($decoded, $http_code),
			'body'      => $decoded,
		);
	}

	private function extract_error($decoded, $http_code)
	{
		if ($http_code === 429) {
			return 'Rate limited (429) by Channex';
		}
		if (is_array($decoded) && isset($decoded['errors'])) {
			return json_encode($decoded['errors']);
		}
		if (is_array($decoded) && isset($decoded['message'])) {
			return $decoded['message'];
		}
		return 'HTTP ' . $http_code;
	}
}
