<?php

/**
 * Regolith Sync Database Repository
 *
 * Owns the 4 custom tables and every query that touches them:
 * - Sync Runs     (one row per Full / Delta run, also carries the run lease lock)
 * - Sync Batches  (frozen, immutable payloads, max 100 rows each)
 * - Sync Queue    (durable delta queue, one row per product / variation)
 * - Product State (local ledger of the last server answer per product / variation)
 *
 * Every datetime column here is UTC. Use self::now() and never current_time('mysql')
 * without the gmt flag, otherwise retry times drift by the site's timezone offset.
 *
 * The rules the engine relies on are written down in .claude/notes/sync-design.md (repo root).
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Sync_DB
{
	/**
	 * Bump this whenever a CREATE TABLE below changes. dbDelta() only runs when the stored
	 * version is lower, so forgetting to bump means the new columns never show up.
	 * (It used to piggyback on the plugin version, which never moved.)
	 */
	const SCHEMA_VERSION = '3';

	/**
	 * How long a run lease lasts. Must be longer than the slowest single batch request
	 * (60s HTTP timeout + row processing), so a live worker never loses its lock.
	 */
	const RUN_LOCK_SECONDS = 120;

	/**
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
		$installed_ver = get_option('regolith_sync_db_version', '0');
		if (version_compare($installed_ver, self::SCHEMA_VERSION, '<')) {
			$this->create_tables();
		}
	}

	/**
	 * Current UTC time in MySQL format. The one clock every table uses.
	 *
	 * @param  int $offset_seconds Optional offset from now (can be negative).
	 * @return string
	 */
	public static function now($offset_seconds = 0)
	{
		return gmdate('Y-m-d H:i:s', time() + (int) $offset_seconds);
	}

	// MARK: Schema

	/**
	 * Create or update custom database tables.
	 */
	public function create_tables()
	{
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_runs    = $wpdb->prefix . 'regolith_sync_runs';
		$table_batches = $wpdb->prefix . 'regolith_sync_batches';
		$table_queue   = $wpdb->prefix . 'regolith_sync_queue';
		$table_state   = $wpdb->prefix . 'regolith_product_sync_state';

		// build_cursor / build_buffer: a Full run enumerates the catalog over several steps
		// (status Building). The cursor is the last parent product ID handled, the buffer holds
		// serialized rows that don't fill a batch yet.
		// lock_token / locked_until: the run lease. Only the holder may send this run's batches.
		$sql = "CREATE TABLE {$table_runs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sync_id VARCHAR(64) NOT NULL,
			mode VARCHAR(20) NOT NULL DEFAULT 'Full',
			status VARCHAR(30) NOT NULL DEFAULT 'InProgress',
			server_status VARCHAR(30) NULL,
			started_at DATETIME NOT NULL,
			completed_at DATETIME NULL,
			total_items INT(11) NOT NULL DEFAULT 0,
			total_batches INT(11) NOT NULL DEFAULT 0,
			completed_batches INT(11) NOT NULL DEFAULT 0,
			failed_batches INT(11) NOT NULL DEFAULT 0,
			created_count INT(11) NOT NULL DEFAULT 0,
			updated_count INT(11) NOT NULL DEFAULT 0,
			unchanged_count INT(11) NOT NULL DEFAULT 0,
			deactivated_count INT(11) NOT NULL DEFAULT 0,
			skipped_count INT(11) NOT NULL DEFAULT 0,
			stale_count INT(11) NOT NULL DEFAULT 0,
			rejected_count INT(11) NOT NULL DEFAULT 0,
			build_cursor BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			build_buffer LONGTEXT NULL,
			lock_token VARCHAR(64) NULL,
			locked_until DATETIME NULL,
			last_error_code VARCHAR(100) NULL,
			last_error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sync_id (sync_id),
			KEY mode (mode),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$table_batches} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			sync_run_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			sync_id VARCHAR(64) NOT NULL,
			batch_number INT(11) NOT NULL,
			is_final TINYINT(1) NOT NULL DEFAULT 0,
			item_count INT(11) NOT NULL DEFAULT 0,
			payload LONGTEXT NOT NULL,
			payload_hash VARCHAR(64) NOT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			attempt_count INT(11) NOT NULL DEFAULT 0,
			last_http_status INT(11) NULL,
			last_error_code VARCHAR(100) NULL,
			last_error_message TEXT NULL,
			created_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			completed_at DATETIME NULL,
			next_retry_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sync_batch (sync_id, batch_number),
			KEY sync_run_id (sync_run_id),
			KEY status (status),
			KEY next_retry_at (next_retry_at)
		) {$charset_collate};

		CREATE TABLE {$table_queue} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			operation VARCHAR(20) NOT NULL DEFAULT 'update',
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			revision INT(11) UNSIGNED NOT NULL DEFAULT 1,
			sent_revision INT(11) UNSIGNED NOT NULL DEFAULT 0,
			sync_id VARCHAR(64) NULL,
			attempt_count INT(11) NOT NULL DEFAULT 0,
			last_error_code VARCHAR(100) NULL,
			last_error_message TEXT NULL,
			next_retry_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_variation (product_id, variation_id),
			KEY status (status),
			KEY sync_id (sync_id),
			KEY next_retry_at (next_retry_at)
		) {$charset_collate};

		CREATE TABLE {$table_state} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			external_product_id VARCHAR(100) NOT NULL,
			external_variation_id VARCHAR(100) NULL,
			last_external_updated_at VARCHAR(50) NULL,
			last_payload_hash VARCHAR(64) NULL,
			last_sync_status VARCHAR(50) NULL,
			last_sync_id VARCHAR(64) NULL,
			last_item_id BIGINT(20) UNSIGNED NULL,
			last_error_code VARCHAR(100) NULL,
			last_error_message TEXT NULL,
			last_error_retryable TINYINT(1) NOT NULL DEFAULT 0,
			last_warning_code VARCHAR(100) NULL,
			last_warning_message TEXT NULL,
			is_active TINYINT(1) NULL,
			retry_count INT(11) NOT NULL DEFAULT 0,
			last_success_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY prod_var (product_id, variation_id),
			KEY external_product_id (external_product_id),
			KEY last_sync_status (last_sync_status),
			KEY last_error_code (last_error_code)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		update_option('regolith_sync_db_version', self::SCHEMA_VERSION);
	}

	// MARK: Sync Runs

	public function create_sync_run(array $data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		$now   = self::now();

		$defaults = [
			'sync_id'    => wp_generate_uuid4(),
			'mode'       => 'Full',
			'status'     => 'InProgress',
			'started_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		];

		$insert = wp_parse_args($data, $defaults);
		$wpdb->insert($table, $insert);

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	public function get_sync_run_by_sync_id($sync_id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE sync_id = %s", $sync_id), ARRAY_A);
	}

	/**
	 * Latest run, optionally filtered by mode and/or a list of statuses.
	 */
	public function get_latest_sync_run($mode = null, array $statuses = [])
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';

		$where = ['1=1'];
		$args  = [];

		if ($mode) {
			$where[] = 'mode = %s';
			$args[]  = $mode;
		}
		if ($statuses) {
			$where[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
			$args    = array_merge($args, $statuses);
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1';
		return $wpdb->get_row($args ? $wpdb->prepare($sql, ...$args) : $sql, ARRAY_A);
	}

	/**
	 * Runs that still have work: Building (Full snapshot being enumerated) or InProgress.
	 * Oldest first, so whatever started first finishes first.
	 */
	public function get_active_runs()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		return $wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('Building', 'InProgress') ORDER BY id ASC", ARRAY_A);
	}

	public function get_active_full_sync_run()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		return $wpdb->get_row("SELECT * FROM {$table} WHERE mode = 'Full' AND status IN ('Building', 'InProgress') ORDER BY id DESC LIMIT 1", ARRAY_A);
	}

	public function update_sync_run($sync_id, array $data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		$data['updated_at'] = self::now();
		return $wpdb->update($table, $data, ['sync_id' => $sync_id]);
	}

	/**
	 * Add to the run counters in one statement (no read-modify-write race).
	 *
	 * @param string $sync_id
	 * @param array  $deltas  column => amount, e.g. ['created_count' => 3].
	 */
	public function increment_run_counters($sync_id, array $deltas)
	{
		global $wpdb;
		$table   = $wpdb->prefix . 'regolith_sync_runs';
		$allowed = ['completed_batches', 'failed_batches', 'created_count', 'updated_count', 'unchanged_count', 'deactivated_count', 'skipped_count', 'stale_count', 'rejected_count'];

		$sets = [];
		foreach ($deltas as $column => $amount) {
			if (in_array($column, $allowed, true) && (int) $amount !== 0) {
				$sets[] = "{$column} = {$column} + " . (int) $amount;
			}
		}
		if (! $sets) {
			return 0;
		}

		return $wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET " . implode(', ', $sets) . ", updated_at = %s WHERE sync_id = %s",
			self::now(),
			$sync_id
		));
	}

	/**
	 * Only for runs that never got a batch (empty deltas). Anything that reached the server
	 * stays in the history.
	 */
	public function delete_sync_run($sync_id)
	{
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'regolith_sync_batches', ['sync_id' => $sync_id]);
		return $wpdb->delete($wpdb->prefix . 'regolith_sync_runs', ['sync_id' => $sync_id]);
	}

	public function get_recent_sync_runs($limit = 10)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
	}

	// MARK: Run Lock

	/**
	 * Try to take the lease on a run. Atomic: only one UPDATE can win, whether the other
	 * contender is a cron runner, an async Action Scheduler request or the admin page.
	 * An expired lease (crashed worker) can be taken over.
	 *
	 * @return bool True when this caller now owns the run.
	 */
	public function acquire_run_lock($sync_id, $token)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		$now   = self::now();

		$affected = $wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			SET lock_token = %s, locked_until = %s
			WHERE sync_id = %s
			AND (lock_token IS NULL OR locked_until IS NULL OR locked_until < %s OR lock_token = %s)",
			$token,
			self::now(self::RUN_LOCK_SECONDS),
			$sync_id,
			$now,
			$token
		));

		return 1 === (int) $affected;
	}

	public function release_run_lock($sync_id, $token)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_runs';
		return $wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET lock_token = NULL, locked_until = NULL WHERE sync_id = %s AND lock_token = %s",
			$sync_id,
			$token
		));
	}

	// MARK: Sync Batches

	public function create_sync_batch(array $data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';

		$defaults = [
			'sync_run_id'   => 0,
			'sync_id'       => '',
			'batch_number'  => 1,
			'is_final'      => 0,
			'item_count'    => 0,
			'payload'       => '',
			'payload_hash'  => '',
			'status'        => 'pending',
			'attempt_count' => 0,
			'created_at'    => self::now(),
		];

		$insert = wp_parse_args($data, $defaults);
		$wpdb->insert($table, $insert);

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	public function get_batch($sync_id, $batch_number)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE sync_id = %s AND batch_number = %d", $sync_id, $batch_number), ARRAY_A);
	}

	/**
	 * The ONLY batch of a run that may be sent next: the lowest-numbered one that isn't
	 * completed yet. Whether it's due (retry_wait) is the caller's call, we never skip past it.
	 * This is what keeps us clear of SYNC_BATCH_OUT_OF_SEQUENCE.
	 */
	public function get_next_batch_for_run($sync_id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE sync_id = %s AND status <> 'completed' ORDER BY batch_number ASC LIMIT 1",
				$sync_id
			),
			ARRAY_A
		);
	}

	public function update_batch($id, array $data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return $wpdb->update($table, $data, ['id' => $id]);
	}

	public function get_batches_for_run($sync_id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE sync_id = %s ORDER BY batch_number ASC", $sync_id), ARRAY_A);
	}

	/**
	 * Has anything from this run reached (or maybe reached) the server?
	 * Used to decide whether an abandon call is needed at all.
	 */
	public function run_has_sent_batches($sync_id)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE sync_id = %s AND attempt_count > 0", $sync_id));
	}

	public function close_open_batches($sync_id, $status = 'abandoned')
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_batches';
		return $wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = %s WHERE sync_id = %s AND status IN ('pending', 'retry_wait', 'processing')",
			$status,
			$sync_id
		));
	}

	// MARK: Queue

	/**
	 * Put a product / variation on the delta queue (or nudge the row that's already there).
	 *
	 * One atomic upsert, so two hooks firing for the same save can't race on the unique key.
	 * - `operation` takes the latest value. Trash then restore = update, as it should be.
	 *   (The serializer looks at the real post state anyway; operation only matters once the
	 *   post is gone for good.)
	 * - `revision` always goes up. A row that's in flight keeps its status, and when its batch
	 *   lands the bumped revision tells us there's a newer change to send.
	 * - A failed row becomes pending again: a new edit deserves a fresh try.
	 *
	 * @param int    $product_id   Parent product ID (for variations too).
	 * @param int    $variation_id 0 for non-variations.
	 * @param string $operation    'update' | 'delete'.
	 * @param int    $delay        Seconds before it may be sent (row-level retries).
	 * @param int    $attempts     Attempt counter to store (row-level retries).
	 */
	public function enqueue_item($product_id, $variation_id = 0, $operation = 'update', $delay = 0, $attempts = 0)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		$now   = self::now();

		$operation     = ('delete' === $operation) ? 'delete' : 'update';
		$next_retry_at = $delay > 0 ? self::now($delay) : null;

		return $wpdb->query($wpdb->prepare(
			"INSERT INTO {$table}
				(product_id, variation_id, operation, status, revision, attempt_count, next_retry_at, created_at, updated_at)
			VALUES (%d, %d, %s, 'pending', 1, %d, " . ($next_retry_at ? '%s' : 'NULL') . ", %s, %s)
			ON DUPLICATE KEY UPDATE
				operation = VALUES(operation),
				revision = revision + 1,
				status = IF(status = 'in_flight', 'in_flight', 'pending'),
				attempt_count = VALUES(attempt_count),
				next_retry_at = VALUES(next_retry_at),
				last_error_code = NULL,
				last_error_message = NULL,
				updated_at = VALUES(updated_at)",
			...array_values(array_filter(
				[(int) $product_id, (int) $variation_id, $operation, (int) $attempts, $next_retry_at, $now, $now],
				static fn ($v) => null !== $v
			))
		));
	}

	/**
	 * Atomically hand up to $limit due pending rows to a Delta run. Two workers calling this at
	 * the same moment get disjoint sets (MySQL single-table UPDATE ... ORDER BY ... LIMIT).
	 *
	 * @return array Claimed rows.
	 */
	public function claim_queue_items($sync_id, $limit = 100)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		$now   = self::now();

		$wpdb->query($wpdb->prepare(
			"UPDATE {$table}
			SET status = 'in_flight', sync_id = %s, sent_revision = revision, updated_at = %s
			WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s)
			ORDER BY id ASC
			LIMIT %d",
			$sync_id,
			$now,
			$now,
			$limit
		));

		return $wpdb->get_results(
			$wpdb->prepare("SELECT * FROM {$table} WHERE sync_id = %s AND status = 'in_flight' ORDER BY id ASC", $sync_id),
			ARRAY_A
		);
	}

	/**
	 * A Delta batch landed: drop its queue rows, except the ones edited again while in flight,
	 * those go back to pending so the newer state gets sent.
	 *
	 * @param string   $sync_id
	 * @param int[]    $only_ids Limit to these queue IDs (null = every row of the run).
	 */
	public function resolve_queue_items($sync_id, $only_ids = null)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';

		$id_sql = '';
		if (is_array($only_ids)) {
			$only_ids = array_map('intval', $only_ids);
			if (! $only_ids) {
				return;
			}
			$id_sql = ' AND id IN (' . implode(',', $only_ids) . ')';
		}

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$table} WHERE sync_id = %s AND status = 'in_flight' AND revision = sent_revision{$id_sql}",
			$sync_id
		));

		$wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', sync_id = NULL, updated_at = %s WHERE sync_id = %s AND status = 'in_flight'{$id_sql}",
			self::now(),
			$sync_id
		));
	}

	/**
	 * A Delta run is over without landing: put its rows back (pending) or park them (failed).
	 * Rows that were edited again in the meantime always go back to pending.
	 */
	public function release_queue_items($sync_id, $status = 'pending', $error_code = null, $error_message = null)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		$now   = self::now();

		if ('failed' === $status) {
			$wpdb->query($wpdb->prepare(
				"UPDATE {$table}
				SET status = 'failed', sync_id = NULL, last_error_code = %s, last_error_message = %s, updated_at = %s
				WHERE sync_id = %s AND status = 'in_flight' AND revision = sent_revision",
				(string) $error_code,
				(string) $error_message,
				$now,
				$sync_id
			));
		}

		$wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', sync_id = NULL, updated_at = %s WHERE sync_id = %s AND status = 'in_flight'",
			$now,
			$sync_id
		));
	}

	public function count_due_queue_items()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND (next_retry_at IS NULL OR next_retry_at <= %s)",
			self::now()
		));
	}

	public function get_queue_counts()
	{
		global $wpdb;
		$table   = $wpdb->prefix . 'regolith_sync_queue';
		$results = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status", ARRAY_A);

		$counts = [
			'pending'   => 0,
			'in_flight' => 0,
			'failed'    => 0,
			'waiting'   => 0, // pending, but parked until next_retry_at
			'total'     => 0,
		];

		foreach ((array) $results as $row) {
			if (isset($counts[$row['status']])) {
				$counts[$row['status']] = (int) $row['cnt'];
			}
			$counts['total'] += (int) $row['cnt'];
		}

		$counts['waiting'] = max(0, $counts['pending'] - $this->count_due_queue_items());

		return $counts;
	}

	public function get_failed_queue_items($limit = 50)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = 'failed' ORDER BY updated_at DESC LIMIT %d", $limit), ARRAY_A);
	}

	public function retry_failed_queue_items()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_sync_queue';
		return $wpdb->query($wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', next_retry_at = NULL, attempt_count = 0, updated_at = %s WHERE status = 'failed'",
			self::now()
		));
	}

	public function remove_queue_items(array $ids)
	{
		if (empty($ids)) {
			return 0;
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'regolith_sync_queue';
		$placeholders = implode(',', array_fill(0, count($ids), '%d'));
		return $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id IN ($placeholders)", ...array_map('intval', $ids)));
	}

	// MARK: Product State Ledger

	public function update_product_state(array $data)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_product_sync_state';

		$product_id   = isset($data['product_id']) ? (int) $data['product_id'] : 0;
		$variation_id = isset($data['variation_id']) ? (int) $data['variation_id'] : 0;

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE product_id = %d AND variation_id = %d",
				$product_id,
				$variation_id
			),
			ARRAY_A
		);

		$data['updated_at'] = self::now();

		if ($existing) {
			$wpdb->update($table, $data, ['id' => $existing['id']]);
			return (int) $existing['id'];
		}

		$wpdb->insert($table, $data);
		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	public function get_product_state($product_id, $variation_id = 0)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_product_sync_state';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d",
				$product_id,
				$variation_id
			),
			ARRAY_A
		);
	}

	/**
	 * Products that need a human: rejected rows, or anything still carrying an error code.
	 * Warnings alone (PRICE_MISSING, STALE_EXTERNAL_UPDATE...) don't land here.
	 */
	public function get_rejected_products($limit = 50, $offset = 0)
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_product_sync_state';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE last_sync_status IN ('Rejected', 'Failed', 'Error')
				OR (last_error_code IS NOT NULL AND last_error_code != '')
				ORDER BY updated_at DESC
				LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	public function count_rejected_products()
	{
		global $wpdb;
		$table = $wpdb->prefix . 'regolith_product_sync_state';
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			WHERE last_sync_status IN ('Rejected', 'Failed', 'Error')
			OR (last_error_code IS NOT NULL AND last_error_code != '')"
		);
	}

	/**
	 * Ledger counts for the dashboard.
	 *
	 * "Live" is decided by is_active, NOT by the last status: Regolith answers Unchanged for an
	 * item that is unchanged AND inactive too. Rows written before is_active existed (NULL)
	 * fall back to the status.
	 */
	public function get_sync_totals()
	{
		global $wpdb;
		$table   = $wpdb->prefix . 'regolith_product_sync_state';
		$results = $wpdb->get_results(
			"SELECT
				CASE
					WHEN last_sync_status IN ('Rejected', 'Failed', 'Error', 'Conflict') OR (last_error_code IS NOT NULL AND last_error_code <> '') THEN 'rejected'
					WHEN last_sync_status = 'Skipped' THEN 'skipped'
					WHEN is_active = 1 THEN 'created'
					WHEN is_active = 0 THEN 'deactivated'
					ELSE LOWER(last_sync_status)
				END AS last_sync_status,
				COUNT(*) AS cnt
			FROM {$table}
			GROUP BY 1",
			ARRAY_A
		);

		$totals = [
			'total'       => 0,
			'synced'      => 0,
			'deactivated' => 0,
			'skipped'     => 0,
			'stale'       => 0,
			'rejected'    => 0,
		];

		foreach ((array) $results as $row) {
			$status = strtolower($row['last_sync_status'] ?? '');
			$cnt    = (int) $row['cnt'];
			$totals['total'] += $cnt;

			if (in_array($status, ['created', 'updated', 'unchanged'], true)) {
				$totals['synced'] += $cnt;
			} elseif ('deactivated' === $status) {
				$totals['deactivated'] += $cnt;
			} elseif ('skipped' === $status) {
				$totals['skipped'] += $cnt;
			} elseif ('staleignored' === $status) {
				$totals['stale'] += $cnt;
			} else {
				// Rejected, Conflict (old servers), Failed and anything we don't recognise yet.
				$totals['rejected'] += $cnt;
			}
		}

		return $totals;
	}

	// MARK: Dev Tools

	/**
	 * Wipe every local sync table. Dev/testing only, the server keeps its own state, so after
	 * this the next Full sync may hit FULL_SYNC_ALREADY_RUNNING once (the engine abandons the
	 * leftover run on its own).
	 */
	public function nuke_all_tables()
	{
		global $wpdb;

		$tables = [
			$wpdb->prefix . 'regolith_sync_runs',
			$wpdb->prefix . 'regolith_sync_batches',
			$wpdb->prefix . 'regolith_sync_queue',
			$wpdb->prefix . 'regolith_product_sync_state',
		];

		foreach ($tables as $table) {
			$wpdb->query("TRUNCATE TABLE {$table}");
		}

		delete_option('regolith_sync_blocked');

		return true;
	}
}
