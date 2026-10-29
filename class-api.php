<?php

/**
 * Regolith API Client
 *
 * Abstraction layer over the Regolith REST API.
 * Uses native WordPress HTTP functions (wp_remote_*) instead of cURL.
 * Handles Regolith API Key authentication securely.
 *
 * This class only talks HTTP. It doesn't decide when to retry or what a failure means
 * for a sync run, that's Reg_Sync_Service / Reg_Onboarding territory. What it does do is
 * hand them everything they need to decide: HTTP status, the leading error CODE, the
 * response `data` (e.g. the open syncId on FULL_SYNC_ALREADY_RUNNING) and Retry-After.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_API
{

	/**
	 * Base URL for the Regolith API (no trailing slash).
	 *
	 * @var string
	 */
	private $base_url = '';

	/**
	 * Default timeout in seconds for HTTP requests.
	 *
	 * @var int
	 */
	private $timeout = 30;

	// MARK: Construction

	/**
	 * Returns the singleton instance of this class.
	 *
	 * @return self
	 */
	public static function I()
	{
		static $instance = null;
		if (null === $instance) {
			$instance = new self();
		}
		return $instance;
	}
	public function __construct()
	{
		$this->base_url = untrailingslashit(get_option('regolith_api_url', ''));
	}

	// MARK: Req
	/**
	 * Send an authenticated request to the Regolith API using the Vendor Integration API Key.
	 *
	 * @param  string $method   HTTP method (GET, POST, PUT, DELETE, PATCH).
	 * @param  string $endpoint Relative path, e.g. "/api/v1/vendor-integration/stores/...".
	 * @param  array  $args     {
	 *     Optional. Extra arguments.
	 *
	 *     @type array  $body         Associative array for JSON body.
	 *     @type string $raw_body     Already-encoded JSON (frozen sync batches go out byte-for-byte).
	 *     @type array  $query        Query parameters.
	 *     @type array  $headers      Extra headers (merged with defaults).
	 *     @type array  $multipart    Multipart fields (when sending form-data).
	 *     @type bool   $no_auth      Skip the Authorization header.
	 *     @type int    $timeout      Override default timeout.
	 * }
	 * @return array|WP_Error Decoded response body (associative array) or WP_Error.
	 */
	public function request($method, $endpoint, $args = [])
	{
		// Re-read every time: the wizard saves the URL and calls the API in the same request,
		// and this object is a singleton built long before that.
		$this->base_url = untrailingslashit(get_option('regolith_api_url', ''));

		$method  = strtoupper($method);
		$url     = $this->base_url . $endpoint;

		if (empty($this->base_url)) {
			return new WP_Error(
				'regolith_missing_api_url',
				__('Regolith API URL is not configured. Please set it in the plugin settings.', 'regolith-integration')
			);
		}

		// Append query parameters.
		if (! empty($args['query'])) {
			$url = add_query_arg($args['query'], $url);
		}

		// Build headers.
		$headers = isset($args['headers']) ? $args['headers'] : [];
		$headers['Accept'] = 'application/json';

		// Add Authorization header unless explicitly skipped.
		if (empty($args['no_auth'])) {
			$api_key = get_option('regolith_api_key', '');
			if (empty($api_key)) {
				return new WP_Error(
					'regolith_missing_api_key',
					__('Regolith API Key is not configured. Please set it in the plugin settings.', 'regolith-integration')
				);
			}
			$headers['Authorization'] = 'RegolithKey ' . $api_key;
		}

		// Determine body.
		$body = null;

		if (! empty($args['raw_body'])) {
			$headers['Content-Type'] = 'application/json';
			$body = $args['raw_body'];
		} elseif (! empty($args['multipart'])) {
			$boundary = wp_generate_password(24, false);
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$body = $this->build_multipart_body($args['multipart'], $boundary);
		} elseif (! empty($args['body'])) {
			$headers['Content-Type'] = 'application/json';
			$body = wp_json_encode($args['body']);
		} elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
			// Bodiless POSTs (abandon endpoints) still get a JSON content type, some stacks 415 without it.
			$headers['Content-Type'] = 'application/json';
			$body = '{}';
		}

		$wp_args = [
			'method'  => $method,
			'timeout' => isset($args['timeout']) ? $args['timeout'] : $this->timeout,
			'headers' => $headers,
		];

		if ($body !== null) {
			$wp_args['body'] = $body;
		}

		$response = wp_remote_request($url, $wp_args);

		if (is_wp_error($response)) {
			// Transport failure (DNS, TLS, timeout...). Flag it so callers know it never reached
			// the API and is always safe to retry.
			$response->add_data(['transport' => true, 'http_code' => null]);
			return $response;
		}

		return $this->parse_response($response);
	}
	/**
	 * Build a multipart/form-data body string.
	 *
	 * @param  array  $fields   Array of field arrays.
	 * @param  string $boundary Multipart boundary string.
	 * @return string           Raw multipart body.
	 */
	private function build_multipart_body($fields, $boundary)
	{
		$body = '';

		foreach ($fields as $field) {
			$body .= '--' . $boundary . "\r\n";

			if (! empty($field['filename'])) {
				$mime  = ! empty($field['type']) ? $field['type'] : 'application/octet-stream';
				$body .= sprintf(
					'Content-Disposition: form-data; name="%s"; filename="%s"' . "\r\n",
					$field['name'],
					$field['filename']
				);
				$body .= 'Content-Type: ' . $mime . "\r\n\r\n";
				$body .= $field['contents'] . "\r\n";
			} else {
				$body .= sprintf(
					'Content-Disposition: form-data; name="%s"' . "\r\n\r\n",
					$field['name']
				);
				$body .= $field['contents'] . "\r\n";
			}
		}

		$body .= '--' . $boundary . '--' . "\r\n";

		return $body;
	}
	/**
	 * Parse a wp_remote_* response into a usable array.
	 *
	 * Two body shapes come back from Regolith:
	 * - the usual envelope `{ statusCode, isSuccess, messages, data }`
	 * - a bare `data` object on 201 Created (app-wide behaviour, see LATEST doc)
	 * Use self::data() to read either one without caring which it was.
	 *
	 * Every WP_Error from here carries: http_code, response (decoded body), error_code
	 * (leading CODE of the first message, if any), data (response.data) and retry_after
	 * (seconds, from the Retry-After header).
	 *
	 * @param  array $response Raw WP HTTP response.
	 * @return array|WP_Error  Decoded body or WP_Error on failure.
	 */
	private function parse_response($response)
	{
		$code = (int) wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		// 204 / empty 2xx: nothing to decode, still a success.
		if ('' === trim((string) $body) && $code >= 200 && $code < 300) {
			return [];
		}

		$data = json_decode($body, true);

		$retry_after = wp_remote_retrieve_header($response, 'retry-after');
		$retry_after = is_numeric($retry_after) ? (int) $retry_after : null;

		if (json_last_error() !== JSON_ERROR_NONE) {
			return new WP_Error(
				'regolith_json_error',
				// Model-validation 400s and proxy errors come back as HTML/plain text.
				sprintf(__('API returned HTTP %d with a non-JSON body.', 'regolith-integration'), $code),
				[
					'http_code'   => $code,
					'raw_body'    => mb_substr((string) $body, 0, 2000),
					'retry_after' => $retry_after,
				]
			);
		}

		$is_error = ($code < 200 || $code >= 300) || (is_array($data) && isset($data['isSuccess']) && false === $data['isSuccess']);

		if (! $is_error) {
			return is_array($data) ? $data : [];
		}

		$messages = [];
		if (is_array($data) && ! empty($data['messages'])) {
			$messages = (array) $data['messages'];
		} elseif (is_array($data) && ! empty($data['title'])) {
			// ASP.NET ProblemDetails (model validation 400s have no CODE, per the LATEST doc).
			$messages = [$data['title']];
			if (! empty($data['errors']) && is_array($data['errors'])) {
				foreach ($data['errors'] as $field => $errs) {
					$messages[] = $field . ': ' . implode(' ', (array) $errs);
				}
			}
		}

		$message    = $messages ? implode(' | ', $messages) : sprintf(__('API returned HTTP %d.', 'regolith-integration'), $code);
		$error_code = null;
		if ($messages && preg_match('/^([A-Z0-9_]+):\s*/', (string) $messages[0], $m)) {
			$error_code = $m[1];
		}

		return new WP_Error(
			($code < 200 || $code >= 300) ? 'regolith_http_error' : 'regolith_api_error',
			$message,
			[
				'http_code'   => $code,
				'response'    => $data,
				'error_code'  => $error_code,
				'data'        => (is_array($data) && isset($data['data'])) ? $data['data'] : null,
				'retry_after' => $retry_after,
			]
		);
	}

	/**
	 * Read the payload of a successful response, whichever shape it came in.
	 *
	 * @param  array $response Successful response from request().
	 * @return array
	 */
	public static function data($response)
	{
		if (! is_array($response)) {
			return [];
		}
		// Enveloped: has isSuccess/statusCode next to data.
		if (array_key_exists('data', $response) && (isset($response['isSuccess']) || isset($response['statusCode']))) {
			return is_array($response['data']) ? $response['data'] : [];
		}
		// Bare 201 body.
		return $response;
	}

	/**
	 * Extract error code and human-readable message from a WP_Error or API response.
	 * Server error pattern: "CODE: human-readable message"
	 *
	 * Always branch on `code`, never on `message` (the spec is explicit about that).
	 *
	 * @param  WP_Error|array $error_or_response
	 * @return array{code: string, message: string, http_code: int|null, data: mixed, retry_after: int|null, transport: bool}
	 */
	public function extract_error_info($error_or_response)
	{
		$info = [
			'code'        => 'UNKNOWN_ERROR',
			'message'     => 'An unknown error occurred.',
			'http_code'   => null,
			'data'        => null,
			'retry_after' => null,
			'transport'   => false,
		];

		if (is_wp_error($error_or_response)) {
			$raw_message = $error_or_response->get_error_message();
			$error_data  = $error_or_response->get_error_data();

			if (is_array($error_data)) {
				$info['http_code']   = isset($error_data['http_code']) ? (int) $error_data['http_code'] : null;
				$info['data']        = $error_data['data'] ?? null;
				$info['retry_after'] = $error_data['retry_after'] ?? null;
				$info['transport']   = ! empty($error_data['transport']);
			}
			if (0 === $info['http_code']) {
				$info['http_code'] = null;
			}

			// Check if message starts with CODE:
			if (preg_match('/^([A-Z0-9_]+):\s*(.+)$/s', $raw_message, $matches)) {
				$info['code']    = $matches[1];
				$info['message'] = $matches[2];
			} else {
				$info['code']    = $error_or_response->get_error_code();
				$info['message'] = $raw_message;
			}
		} elseif (is_array($error_or_response)) {
			if (! empty($error_or_response['messages'])) {
				$raw_message = is_array($error_or_response['messages']) ? implode(' | ', $error_or_response['messages']) : (string) $error_or_response['messages'];
				if (preg_match('/^([A-Z0-9_]+):\s*(.+)$/s', $raw_message, $matches)) {
					$info['code']    = $matches[1];
					$info['message'] = $matches[2];
				} else {
					$info['message'] = $raw_message;
				}
			}
		}

		return $info;
	}

	/**
	 * The integer Regolith store ID, or 0 when the store was never registered.
	 */
	public function get_store_id()
	{
		return absint(get_option('regolith_store_id', 0));
	}

	/**
	 * MARK: submit_inventory_batch
	 * Submit an inventory synchronization batch to Regolith.
	 *
	 * @param  int          $store_id Store integer ID.
	 * @param  array|string $payload  Complete batch payload (raw JSON string or associative array).
	 * @return array|WP_Error
	 */
	public function submit_inventory_batch($store_id, $payload)
	{
		$store_id = absint($store_id);
		if (! $store_id) {
			return new WP_Error('regolith_unregistered_store', __('Store registration is required before submitting inventory batches.', 'regolith-integration'));
		}

		$req_args = [
			'timeout' => 60,
		];

		// Frozen batches are always sent as the stored string. Re-encoding an array could
		// change key order / number formatting and the server would see a "changed" replay.
		if (is_string($payload)) {
			$req_args['raw_body'] = $payload;
		} else {
			$req_args['body'] = $payload;
		}

		return $this->request(
			'POST',
			"/api/v1/vendor-integration/stores/{$store_id}/inventory/sync",
			$req_args
		);
	}

	/**
	 * MARK: get_sync_run_status
	 * Retrieve current status and aggregated results of a synchronization run.
	 *
	 * @param  int    $store_id Store integer ID.
	 * @param  string $sync_id  UUID of the sync run.
	 * @return array|WP_Error
	 */
	public function get_sync_run_status($store_id, $sync_id)
	{
		$store_id = absint($store_id);
		if (! $store_id) {
			return new WP_Error('regolith_unregistered_store', __('Store registration is required.', 'regolith-integration'));
		}

		return $this->request(
			'GET',
			"/api/v1/vendor-integration/stores/{$store_id}/inventory/sync-runs/" . rawurlencode($sync_id)
		);
	}

	/**
	 * MARK: abandon_sync_run
	 * Abandon an incomplete Full sync run.
	 *
	 * @param  int    $store_id Store integer ID.
	 * @param  string $sync_id  UUID of the Full sync run.
	 * @return array|WP_Error
	 */
	public function abandon_sync_run($store_id, $sync_id)
	{
		$store_id = absint($store_id);
		if (! $store_id) {
			return new WP_Error('regolith_unregistered_store', __('Store registration is required.', 'regolith-integration'));
		}

		return $this->request(
			'POST',
			"/api/v1/vendor-integration/stores/{$store_id}/inventory/sync-runs/" . rawurlencode($sync_id) . '/abandon'
		);
	}

	// MARK: register_store
	/**
	 * Register (or reconnect) this WordPress store and remember the integer storeId.
	 *
	 * - First registration answers 201 with a bare body, reconnects answer 200 with the
	 *   usual envelope. self::data() reads both.
	 * - The externalStoreId UUID is generated once and kept forever. Losing it means
	 *   Regolith sees a "different store" and answers STORE_ALREADY_LINKED.
	 * - siteUrl is immutable on Regolith's side (STORE_SITE_URL_IMMUTABLE).
	 *
	 * @return array|WP_Error Store data (storeId, onboardingStatus...) or WP_Error.
	 */
	public function register_store()
	{
		$external_store_id = get_option('regolith_external_store_id', '');
		if (empty($external_store_id)) {
			$external_store_id = wp_generate_uuid4();
			update_option('regolith_external_store_id', $external_store_id);
		}

		$response = $this->request('POST', '/api/v1/vendor-integration/stores/register', [
			'body' => [
				'externalStoreId'    => $external_store_id,
				'siteUrl'            => untrailingslashit(home_url()),
				'displayName'        => get_bloginfo('name'),
				'pluginVersion'      => REGOLITH_PLUGIN_VERSION,
				'woocommerceVersion' => get_option('woocommerce_version'),
			],
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$store = self::data($response);
		if (! empty($store['storeId'])) {
			update_option('regolith_store_id', absint($store['storeId']));
		}
		if (! empty($store['onboardingStatus'])) {
			update_option('regolith_onboarding_status', sanitize_text_field($store['onboardingStatus']));
		}

		return $store;
	}

	// MARK: test_connection
	/**
	 * Test the API connection by validating authentication credentials.
	 *
	 * With a known storeId we read the mapping state (cheap, read-only). Without one we
	 * register, which is also how a fresh install gets its storeId. A STORE_DISCONNECTED
	 * answer means a Regolith admin unlinked us: registering again reconnects (onboarding
	 * then restarts at CategoryMappingRequired on Regolith's side).
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function test_connection()
	{
		$api_key = get_option('regolith_api_key', '');
		if (empty($api_key)) {
			return new WP_Error('regolith_missing_api_key', __('API Key is missing.', 'regolith-integration'));
		}

		$store_id = $this->get_store_id();

		if ($store_id) {
			$response = $this->get_category_mapping_state();
			$info     = is_wp_error($response) ? $this->extract_error_info($response) : null;

			// Unlinked by staff, or the stored id points nowhere: re-register to reconnect.
			if ($info && in_array($info['code'], ['STORE_DISCONNECTED', 'STORE_NOT_FOUND'], true)) {
				$response = $this->register_store();
			}
		} else {
			$response = $this->register_store();
		}

		if (is_wp_error($response)) {
			// Catch critical access/authorization violations explicitly
			$error_data = $response->get_error_data();
			if (isset($error_data['http_code']) && in_array((int) $error_data['http_code'], [401, 403], true)) {
				return new WP_Error('regolith_auth_invalid', __('Invalid or unauthorized API key.', 'regolith-integration') . ' (' . $response->get_error_message() . ')');
			}
			return $response;
		}

		// A working connection is the signal that whatever blocked the sync engine is fixed.
		delete_option('regolith_sync_blocked');

		return true;
	}


	// MARK: Field Catalog
	/**
	 * The list of item fields Regolith understands (grouped). Built server-side from the
	 * sync DTOs, so it is the authority for attribute keys. Cached for 12h.
	 *
	 * Response shape: data = [ { group, fields: [ {key, label, group, payloadSection, dataType, required} ] } ]
	 *
	 * @param  bool $force_refresh Skip the cache.
	 * @return array|WP_Error Flat list of field definitions.
	 */
	public function get_item_field_catalog($force_refresh = false)
	{
		$transient_key = 'regolith_item_field_catalog';
		if (! $force_refresh) {
			$cached = get_transient($transient_key);
			if (is_array($cached) && $cached) {
				return $cached;
			}
		}

		$response = $this->request('GET', '/api/v1/vendor-integration/item-field-catalog');
		if (is_wp_error($response)) {
			return $response;
		}

		$fields = [];
		foreach (self::data($response) as $group) {
			// Accept both the grouped shape (what the sandbox returns) and a flat list.
			$group_fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [$group];
			foreach ($group_fields as $field) {
				if (! empty($field['key'])) {
					$fields[] = $field;
				}
			}
		}

		set_transient($transient_key, $fields, 12 * HOUR_IN_SECONDS);

		return $fields;
	}

	/**
	 * MARK: Get Remote Cats
	 * Fetches the (Cached) Remote category options structure directly from the Regolith Main API.
	 *
	 * @return array{'id':int,'name':string}[]|WP_Error Decoded response array containing options data or WP_Error.
	 */
	// Droped in Favour of GET category mapping
	// public function get_remote_categories($force_refresh = false)
	// {

	// 	$transient_key = 'regolith_remote_categories_cache';
	// 	if (! $force_refresh) {
	// 		$cached_categories = get_transient($transient_key);
	// 		if (!empty($cached_categories)) {
	// 			return $cached_categories;
	// 		}
	// 	}

	// 	$store_id = get_option('regolith_store_id', '');
	// 	if (empty($store_id)) {
	// 		return new WP_Error(
	// 			'regolith_unregistered_store',
	// 			__('Store registration is required before retrieving category configurations.', 'regolith-integration')
	// 		);
	// 	}
	// 	$response = $this->request('GET', "/api/v1/Category/select-list");
	// 	if (is_wp_error($response)) {
	// 		return [];
	// 	}
	// 	$categories = isset($response['data']) ? $response['data'] : [];
	// 	set_transient($transient_key, $categories, 24 * HOUR_IN_SECONDS);

	// 	return $categories;
	// }

	/**
	 * MARK: Cats Snapshot
	 * Upserts WC Cats to Regolith.
	 *
	 * Always the COMPLETE list: categories missing from it become source-inactive on
	 * Regolith's side. An empty list is refused (CATEGORY_SNAPSHOT_EMPTY) and we don't even
	 * try, WordPress always has "Uncategorized" so empty means something is badly wrong.
	 *
	 * @return array|WP_Error Decoded API response array or WP_Error on failure.
	 */
	public function sync_category_snapshot()
	{
		$store_id = $this->get_store_id();
		if (empty($store_id)) {
			return new WP_Error(
				'regolith_unregistered_store',
				__('Store registration is required before dispatching tracking snapshots.', 'regolith-integration')
			);
		}
		// 2. Fetch all local terms for the designated taxonomy
		$local_terms = get_terms([
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		]);

		if (is_wp_error($local_terms)) {
			return $local_terms;
		}

		if (empty($local_terms)) {
			return new WP_Error('CATEGORY_SNAPSHOT_EMPTY', __('No WooCommerce categories found, refusing to send an empty snapshot.', 'regolith-integration'));
		}

		$categories_payload = [];

		foreach ($local_terms as $term) {
			$parent_id = null;
			if (!empty($term->parent) && $term->parent > 0) {
				$parent_id = (string) $term->parent;
			}

			$categories_payload[] = [
				'externalCategoryId'       => (string) $term->term_id,
				// Term names come HTML-escaped out of the DB ("Hats &amp; Caps"). Max 200 per the API.
				'name'                     => mb_substr(html_entity_decode($term->name, ENT_QUOTES, 'UTF-8'), 0, 200),
				'parentExternalCategoryId' => $parent_id,
				'productCount'             => (int) $term->count,
			];
		}
		$payload = [
			'snapshotId' => 'category-snapshot-' . gmdate('Y-m-d\TH:i:s\Z'),
			'categories' => $categories_payload,
		];

		$response = $this->request(
			'PUT',
			"/api/v1/vendor-integration/stores/{$store_id}/categories",
			['body' => $payload]
		);

		if (! is_wp_error($response)) {
			update_option('regolith_latest_category_snapshot_id', $payload['snapshotId']);
			update_option('regolith_categories_dirty', 0);
		}
		return $response;
	}

	/**
	 * MARK: Complete Onboarding
	 *
	 * Marks the vendor store onboarding as complete.
	 *
	 * @return array|WP_Error Decoded API response or WP_Error.
	 */
	public function complete_onboarding()
	{
		$store_id = $this->get_store_id();

		if (empty($store_id)) {
			return new WP_Error(
				'regolith_unregistered_store',
				__('Store registration is required before completing onboarding.', 'regolith-integration')
			);
		}

		$response = $this->request(
			'POST',
			"/api/v1/vendor-integration/stores/{$store_id}/onboarding/complete",
			[
				'body' => [
					'categorySnapshotId' => get_option('regolith_latest_category_snapshot_id', 'Never'),
				],
			]
		);

		if (! is_wp_error($response)) {
			$data = self::data($response);
			if (! empty($data['onboardingStatus'])) {
				update_option('regolith_onboarding_status', sanitize_text_field($data['onboardingStatus']));
			}
		}

		return $response;
	}

	/**
	 * MARK: Membership Batch
	 *
	 * PUT one product-category membership snapshot batch. The batching itself (cursor,
	 * snapshot id, resume, abandon-on-conflict) lives in Reg_Onboarding.
	 *
	 * @param  array $payload { snapshotId, batchNumber, isFinalBatch, products[] }
	 * @return array|WP_Error
	 */
	public function put_membership_batch(array $payload)
	{
		$store_id = $this->get_store_id();
		if (empty($store_id)) {
			return new WP_Error(
				'regolith_unregistered_store',
				__('Store registration is required before saving product category memberships.', 'regolith-integration')
			);
		}

		return $this->request(
			'PUT',
			"/api/v1/vendor-integration/stores/{$store_id}/product-category-memberships",
			['body' => $payload, 'timeout' => 60]
		);
	}

	/**
	 * Abandon an open membership snapshot (ours, left behind by a crash or a reset).
	 * Repeat calls are 200, a completed one is MEMBERSHIP_SNAPSHOT_COMPLETE.
	 */
	public function abandon_membership_snapshot($snapshot_id)
	{
		$store_id = $this->get_store_id();
		if (empty($store_id)) {
			return new WP_Error('regolith_unregistered_store', __('Store registration is required.', 'regolith-integration'));
		}

		return $this->request(
			'POST',
			"/api/v1/vendor-integration/stores/{$store_id}/product-category-memberships/" . rawurlencode($snapshot_id) . '/abandon'
		);
	}

	/**
	 * MARK: Save Mappings
	 *
	 * Saves category map/ignore decisions to the Regolith API.
	 *
	 * Expected decision structure:
	 *
	 * [
	 *     [
	 *         'vendorCategoryId'   => 3,
	 *         'decision'           => 'Mapped',
	 *         'internalCategoryId' => 33,
	 *     ],
	 *     [
	 *         'vendorCategoryId'   => 5,
	 *         'decision'           => 'Ignored',
	 *         'internalCategoryId' => null,
	 *     ],
	 * ]
	 *
	 * IMPORTANT:
	 * vendorCategoryId must be the Regolith vendor category database ID.
	 * It must NOT be the WooCommerce term_id or externalCategoryId.
	 *
	 * @param array $decisions Array of mapping decision objects.
	 * @return array|WP_Error Decoded response array or WP_Error on failure.
	 */
	// MARK: Save Category Mappings

	public function save_category_mappings(array $mappings): array|WP_Error
	{
		$store_id = $this->get_store_id();

		if (! $store_id) {
			return new WP_Error(
				'regolith_store_missing',
				__('Regolith store is not registered.', 'regolith-integration')
			);
		}

		if (! $mappings) {
			return new WP_Error(
				'regolith_empty_mappings',
				__('No category mappings were provided.', 'regolith-integration')
			);
		}

		return $this->request(
			'PUT',
			"/api/v1/vendor-integration/stores/{$store_id}/category-mappings",
			[
				'body' => [
					'mappings' => array_values($mappings),
				],
			]
		);
	}

	/**
	 * MARK: Category Mapping State
	 *
	 * Fetches the store-specific category mapping state from Regolith.
	 *
	 * This endpoint returns:
	 *
	 * - store                     Store info incl. onboardingStatus.
	 * - vendorCategories          Source categories belonging to this store.
	 * - regolithCategories        Destination/internal Regolith categories.
	 * - pendingProductSelections  Products with several eligible categories.
	 * - progress                  Counters for the onboarding UI.
	 *
	 * IMPORTANT:
	 * vendorCategories.id is NOT the same as
	 * vendorCategories.externalCategoryId.
	 *
	 * The mapper must use:
	 *
	 * WooCommerce term_id
	 *     ->
	 * vendorCategory.externalCategoryId
	 *     ->
	 * vendorCategory.id
	 *
	 * @return array|WP_Error Decoded API response or WP_Error.
	 */
	public function get_category_mapping_state(): array|WP_Error
	{
		$store_id = $this->get_store_id();

		if (! $store_id) {
			return new WP_Error(
				'regolith_store_missing',
				__('Regolith store is not registered.', 'regolith-integration')
			);
		}

		$response = $this->request(
			'GET',
			"/api/v1/vendor-integration/stores/{$store_id}/category-mappings"
		);

		if (! is_wp_error($response)) {
			$data = self::data($response);
			if (! empty($data['store']['onboardingStatus'])) {
				update_option('regolith_onboarding_status', sanitize_text_field($data['store']['onboardingStatus']));
			}
		}

		return $response;
	}

	/**
	 * MARK: Category Selections
	 *
	 * Save explicit category choices for products that sit in several mapped categories.
	 * Max 100 per call (we chunk). Products must be in the latest complete membership snapshot.
	 *
	 * @param  array $selections [ ['externalProductId' => 'product:12', 'vendorCategoryId' => 5], ... ]
	 * @return array|WP_Error Last response, or the first error.
	 */
	public function save_category_selections(array $selections)
	{
		$store_id = $this->get_store_id();
		if (! $store_id) {
			return new WP_Error('regolith_store_missing', __('Regolith store is not registered.', 'regolith-integration'));
		}

		if (! $selections) {
			return new WP_Error('regolith_empty_selections', __('No category selections were provided.', 'regolith-integration'));
		}

		$response = [];
		foreach (array_chunk(array_values($selections), 100) as $chunk) {
			$response = $this->request(
				'PUT',
				"/api/v1/vendor-integration/stores/{$store_id}/category-selections",
				['body' => ['selections' => $chunk]]
			);
			if (is_wp_error($response)) {
				return $response;
			}
		}

		return $response;
	}
}
