<?php

/**
 * Regolith Sync Service
 *
 * The engine. Everything that sends inventory goes through work():
 * - Full runs:  Building (catalog enumerated in small chunks, rows frozen into ≤100-row
 *               batches) → InProgress (batches sent strictly in order) → Completed /
 *               CompletedWithErrors / Failed / Abandoned.
 * - Delta runs: built from the durable queue, always exactly one batch.
 *
 * Who calls work():
 * - Action Scheduler (`regolith_sync_tick` every minute, `regolith_sync_work` to keep going),
 *   see Reg_Sync_Queue.
 * - The Inventory Sync admin page, over AJAX, while someone is watching.
 * Both at once is fine: a run is only worked by whoever holds its lease (Reg_Sync_DB run lock).
 *
 * The invariants this file exists to keep are listed in .claude/notes/sync-design.md.
 * The short version: only ever send the lowest unfinished batch, never rebuild a frozen
 * payload, abandon on the server what we give up on locally, and don't touch the queue until
 * the server has the change.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Sync_Service
{
	/**
	 * Maximum items per sync batch (Specification strictly enforces <= 100).
	 */
	const BATCH_SIZE = 100;

	/**
	 * What we actually send per batch. Smaller than the max on purpose: Regolith imports every
	 * new item's image inside the request, and on the sandbox a 100-row batch of new items took
	 * 30-60+ seconds, right at our 60s HTTP timeout. A timed-out batch isn't lost (the retry
	 * gets SYNC_BATCH_IN_PROGRESS until the server finishes) but it's slow and noisy.
	 * Filter `regolith_sync_batch_size` to tune it (clamped to 1..100).
	 */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * SYNC_BATCH_IN_PROGRESS means the server is still working the batch (or holds a crashed
	 * claim, which it re-processes after 10 minutes). Being patient is right here, so it gets
	 * its own budget: up to this many attempts, at most a minute apart (> 15 minutes total).
	 */
	const MAX_IN_PROGRESS_ATTEMPTS = 25;

	/**
	 * Parent products enumerated per Full build step. Small on purpose: a variable product
	 * with 50 variations is 51 wc_get_product() calls.
	 */
	const BUILD_CHUNK = 25;

	/**
	 * Attempts per batch before the run is given up (≈ 20 minutes of backoff in total).
	 */
	const MAX_BATCH_ATTEMPTS = 8;

	/**
	 * Times a single row with a retryable error (IMAGE_IMPORT_FAILED, ROW_PROCESSING_FAILED...)
	 * is re-queued before we leave it for a human.
	 */
	const MAX_ROW_RETRIES = 5;

	/**
	 * Default seconds of work per work() call. Leaves headroom under the usual 30s PHP limit
	 * for the one batch request that may start right before the deadline (60s timeout, so we
	 * also bump the time limit while sending).
	 */
	const WORK_BUDGET = 20;

	/**
	 * Codes that mean "same request, try again later" (LATEST doc, Plugin Guidance).
	 */
	const RETRYABLE_CODES = ['SYNC_BATCH_IN_PROGRESS', 'CONCURRENT_REQUEST', 'SYNC_RATE_LIMITED'];

	/**
	 * Codes that mean the store / key / vendor itself is broken. Nothing will work until a
	 * human fixes it, so we stop sending (sync "blocked") instead of burning retries.
	 */
	const BLOCKING_CODES = ['STORE_DISCONNECTED', 'STORE_NOT_FOUND', 'STORE_OWNERSHIP_INVALID', 'VENDOR_INTEGRATION_API_KEY_REQUIRED', 'VENDOR_COUNTRY_REQUIRED', 'regolith_missing_api_key', 'regolith_missing_api_url'];

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

	// MARK: Full Sync

	/**
	 * Start a new Full Catalog Synchronization run.
	 *
	 * This only creates the run (status Building). The catalog is enumerated and frozen into
	 * batches by work(), a chunk at a time, so a big catalog never has to fit in one request.
	 *
	 * @return array|WP_Error
	 */
	public function start_full_sync()
	{
		if (! Reg_API::I()->get_store_id()) {
			return new WP_Error('regolith_unregistered_store', __('Store registration is required before running a full sync.', 'regolith-integration'));
		}

		if ($blocked = $this->get_block()) {
			return new WP_Error('regolith_sync_blocked', sprintf(__('Sync is blocked: %s', 'regolith-integration'), $blocked['message']));
		}

		// Concurrency check: local. The server enforces it too (FULL_SYNC_ALREADY_RUNNING).
		if (Reg_Sync_DB::I()->get_active_full_sync_run()) {
			return new WP_Error('FULL_SYNC_ALREADY_RUNNING', __('A Full synchronization is already active.', 'regolith-integration'));
		}

		$sync_id = wp_generate_uuid4();

		$run_id = Reg_Sync_DB::I()->create_sync_run([
			'sync_id'      => $sync_id,
			'mode'         => 'Full',
			'status'       => 'Building',
			'build_cursor' => 0,
			'build_buffer' => '[]',
		]);

		if (! $run_id) {
			return new WP_Error('regolith_db_error', __('Failed to create local sync run record.', 'regolith-integration'));
		}

		Reg_Sync_Queue::I()->schedule_work();

		return [
			'sync_id' => $sync_id,
			'status'  => 'Building',
		];
	}

	/**
	 * Abandon an active run (admin button).
	 *
	 * Full runs that already sent something are abandoned on the server as well, which frees
	 * the store for a new Full run and guarantees no reconciliation happens. Delta runs can't
	 * be abandoned server-side (SYNC_RUN_NOT_FULL) and don't need to be, their queue rows just
	 * go back to pending.
	 *
	 * @param  string $sync_id
	 * @return array|WP_Error
	 */
	public function abandon_run($sync_id)
	{
		$run = Reg_Sync_DB::I()->get_sync_run_by_sync_id($sync_id);
		if (! $run || ! in_array($run['status'], ['Building', 'InProgress'], true)) {
			return new WP_Error('regolith_run_not_active', __('That sync run is not active.', 'regolith-integration'));
		}

		// Don't pull the rug from under a worker that's mid-request.
		$token = wp_generate_uuid4();
		if (! Reg_Sync_DB::I()->acquire_run_lock($sync_id, $token)) {
			return new WP_Error('regolith_run_busy', __('A batch of this run is being sent right now. Try again in a few seconds.', 'regolith-integration'));
		}

		$api_note = 'Nothing was sent yet, abandoned locally only.';
		if ('Full' === $run['mode'] && Reg_Sync_DB::I()->run_has_sent_batches($sync_id)) {
			$api_note = $this->abandon_on_server($sync_id);
		}

		Reg_Sync_DB::I()->close_open_batches($sync_id, 'abandoned');
		if ('Delta' === $run['mode']) {
			Reg_Sync_DB::I()->release_queue_items($sync_id, 'pending');
		}

		Reg_Sync_DB::I()->update_sync_run($sync_id, [
			'status'             => 'Abandoned',
			'build_buffer'       => null,
			'last_error_code'    => 'ABANDONED_BY_ADMIN',
			'last_error_message' => 'Abandoned from wp-admin. ' . $api_note,
			'completed_at'       => Reg_Sync_DB::now(),
		]);

		Reg_Sync_DB::I()->release_run_lock($sync_id, $token);

		return [
			'success' => true,
			'message' => __('Sync run abandoned.', 'regolith-integration') . ' ' . $api_note,
		];
	}

	/**
	 * Call the server's abandon endpoint and describe what happened (for logs/UI).
	 * "Already closed" answers are fine, the goal is just "not open any more".
	 */
	private function abandon_on_server($sync_id)
	{
		$res = Reg_API::I()->abandon_sync_run(Reg_API::I()->get_store_id(), $sync_id);
		if (! is_wp_error($res)) {
			return 'Abandoned on Regolith.';
		}

		$info = Reg_API::I()->extract_error_info($res);
		if (in_array($info['code'], ['SYNC_RUN_COMPLETE', 'SYNC_RUN_NOT_FULL'], true) || 404 === $info['http_code']) {
			return 'Regolith had already closed it (' . $info['code'] . ').';
		}

		return 'Regolith abandon call failed (' . $info['code'] . ': ' . $info['message'] . ').';
	}

	// MARK: Work Loop

	/**
	 * Do as much sync work as fits in $budget seconds, then return.
	 *
	 * Order of business, every loop:
	 * 1. Work each active run we can get the lease for (one step each: a build chunk or one batch).
	 * 2. Nothing active? Start a Delta run from the queue.
	 * 3. Stop when the budget is gone, everything waits on a retry timer, or there's nothing left.
	 *
	 * @param  int $budget Seconds.
	 * @return array{state: string, wait_until: int|null, steps: int}
	 *         state: idle | progress (budget ran out, more to do) | wait | busy | blocked
	 */
	public function work($budget = self::WORK_BUDGET)
	{
		if ($this->get_block()) {
			return ['state' => 'blocked', 'wait_until' => null, 'steps' => 0];
		}

		if (! Reg_API::I()->get_store_id()) {
			return ['state' => 'idle', 'wait_until' => null, 'steps' => 0];
		}

		$deadline   = microtime(true) + max(1, (int) $budget);
		$token      = wp_generate_uuid4();
		$steps      = 0;
		$wait_until = null;
		$busy       = false;

		while (microtime(true) < $deadline) {
			$runs = Reg_Sync_DB::I()->get_active_runs();

			if (! $runs) {
				if ($this->maybe_start_delta()) {
					continue;
				}
				return ['state' => 'idle', 'wait_until' => null, 'steps' => $steps];
			}

			$progressed = false;

			foreach ($runs as $run) {
				if (microtime(true) >= $deadline) {
					break;
				}

				if (! Reg_Sync_DB::I()->acquire_run_lock($run['sync_id'], $token)) {
					$busy = true; // somebody else is on it
					continue;
				}

				try {
					// Re-read under the lock, the row may have moved on since get_active_runs().
					$fresh  = Reg_Sync_DB::I()->get_sync_run_by_sync_id($run['sync_id']);
					$result = $fresh ? $this->step($fresh) : ['state' => 'idle'];
				} catch (Throwable $e) {
					// A bug must not leave the run locked or silently wedged. Fail it loudly.
					$this->fail_run($run, 'PLUGIN_EXCEPTION', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
					$result = ['state' => 'progress'];
				} finally {
					Reg_Sync_DB::I()->release_run_lock($run['sync_id'], $token);
				}

				$steps++;

				if ('progress' === $result['state']) {
					$progressed = true;
				} elseif ('wait' === $result['state'] && ! empty($result['wait_until'])) {
					$wait_until = $wait_until ? min($wait_until, $result['wait_until']) : $result['wait_until'];
				} elseif ('blocked' === $result['state']) {
					return ['state' => 'blocked', 'wait_until' => null, 'steps' => $steps];
				}
			}

			if (! $progressed) {
				if ($wait_until) {
					return ['state' => 'wait', 'wait_until' => $wait_until, 'steps' => $steps];
				}
				return ['state' => $busy ? 'busy' : 'idle', 'wait_until' => null, 'steps' => $steps];
			}
		}

		return ['state' => 'progress', 'wait_until' => null, 'steps' => $steps];
	}

	/**
	 * One unit of work on a run we hold the lease for.
	 *
	 * @return array{state: string, wait_until?: int}
	 */
	private function step(array $run)
	{
		if ('Building' === $run['status'] && 'Delta' === $run['mode']) {
			// maybe_start_delta() is freezing its batch right now, that takes milliseconds.
			// If it's been minutes, that request died half way: hand the rows back.
			if (strtotime($run['created_at'] . ' UTC') < time() - 300) {
				Reg_Sync_DB::I()->release_queue_items($run['sync_id'], 'pending');
				$this->fail_run($run, 'DELTA_BUILD_INTERRUPTED', 'The request building this delta batch died before it finished. Its queue rows were put back.');
				return ['state' => 'progress'];
			}
			return ['state' => 'idle'];
		}
		if ('Building' === $run['status']) {
			return $this->build_step($run);
		}
		if ('InProgress' === $run['status']) {
			return $this->send_step($run);
		}
		return ['state' => 'idle'];
	}

	// MARK: Full Build

	/**
	 * Enumerate the next chunk of the catalog into the run's staging buffer and freeze full
	 * batches out of it.
	 *
	 * The buffer trick: we only freeze 100 rows once we KNOW more rows follow (buffer > 100).
	 * That way the last frozen batch is always the real last one and gets isFinalBatch=true,
	 * and we never have to send an empty final batch or rewrite a frozen one.
	 */
	private function build_step(array $run)
	{
		global $wpdb;

		$sync_id      = $run['sync_id'];
		$cursor       = (int) $run['build_cursor'];
		// Decode as objects so `attributes: {}` stays an object when re-encoded.
		$buffer       = json_decode((string) $run['build_buffer'], false) ?: [];
		$batch_number = (int) $run['total_batches'];
		$total_items  = (int) $run['total_items'];

		$statuses = Reg_Product_Serializer::CATALOG_STATUSES;
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ")
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			...array_merge($statuses, [$cursor, self::BUILD_CHUNK])
		));

		foreach ($ids as $product_id) {
			$cursor = (int) $product_id;
			try {
				foreach (Reg_Product_Serializer::I()->rows_for_product((int) $product_id) as $row) {
					$buffer[] = $row;
					$total_items++;
				}
			} catch (Throwable $e) {
				// One broken product must not kill the catalog snapshot. It's left out of this
				// Full run (so reconciliation will deactivate it, which is the safe side) and
				// shows up in the rejected list with the reason.
				Reg_Sync_DB::I()->update_product_state([
					'product_id'          => (int) $product_id,
					'variation_id'        => 0,
					'external_product_id' => 'product:' . (int) $product_id,
					'last_sync_status'    => 'Failed',
					'last_sync_id'        => $sync_id,
					'last_error_code'     => 'SERIALIZE_FAILED',
					'last_error_message'  => $e->getMessage(),
				]);
			}

			while (count($buffer) > $this->batch_size()) {
				$batch_number++;
				$this->freeze_batch($run, $batch_number, array_splice($buffer, 0, $this->batch_size()), false);
			}
		}

		$enumeration_done = count($ids) < self::BUILD_CHUNK;

		if (! $enumeration_done) {
			Reg_Sync_DB::I()->update_sync_run($sync_id, [
				'build_cursor'  => $cursor,
				'build_buffer'  => wp_json_encode($buffer),
				'total_batches' => $batch_number,
				'total_items'   => $total_items,
			]);
			return ['state' => 'progress'];
		}

		if (! $buffer && 0 === $batch_number) {
			$this->fail_run($run, 'regolith_no_products', __('No products found in WooCommerce to synchronize.', 'regolith-integration'));
			return ['state' => 'progress'];
		}

		// Whatever is left (1..100 rows) is the final batch.
		$batch_number++;
		$this->freeze_batch($run, $batch_number, $buffer, true);

		Reg_Sync_DB::I()->update_sync_run($sync_id, [
			'status'        => 'InProgress',
			'build_cursor'  => $cursor,
			'build_buffer'  => null,
			'total_batches' => $batch_number,
			'total_items'   => $total_items,
		]);

		return ['state' => 'progress'];
	}

	/**
	 * Serialize a batch payload ONCE and store it. From here on the bytes never change:
	 * retries resend exactly this string, so Regolith sees an identical replay.
	 */
	private function freeze_batch(array $run, $batch_number, array $rows, $is_final)
	{
		$payload_data = [
			'syncId'        => $run['sync_id'],
			'mode'          => $run['mode'],
			'batchNumber'   => (int) $batch_number,
			'isFinalBatch'  => (bool) $is_final,
			'storeCurrency' => strtoupper(get_woocommerce_currency()),
			'items'         => array_values($rows),
		];

		$json_payload = wp_json_encode($payload_data);

		return Reg_Sync_DB::I()->create_sync_batch([
			'sync_run_id'   => (int) $run['id'],
			'sync_id'       => $run['sync_id'],
			'batch_number'  => (int) $batch_number,
			'is_final'      => $is_final ? 1 : 0,
			'item_count'    => count($rows),
			'payload'       => $json_payload,
			'payload_hash'  => hash('sha256', $json_payload),
			'status'        => 'pending',
			'attempt_count' => 0,
		]);
	}

	// MARK: Delta Build

	/**
	 * Turn due queue rows into a one-batch Delta run.
	 *
	 * Only when nothing else is active: deltas wait behind a Full run (the spec's recommended
	 * local policy) and behind each other, so there's never more than one open run per mode.
	 *
	 * @return bool True when something happened (a run was created, or queue rows resolved).
	 */
	public function maybe_start_delta()
	{
		if ($this->get_block() || ! Reg_API::I()->get_store_id()) {
			return false;
		}
		// Nothing goes out before "Go live": categories may still be undecided and every row
		// would come back rejected. The queue keeps collecting meanwhile, and the first Full
		// sync sends the whole catalog anyway.
		if (! Reg_Wizard::I()->is_complete()) {
			return false;
		}
		if (Reg_Sync_DB::I()->get_active_runs()) {
			return false;
		}
		if (Reg_Sync_DB::I()->count_due_queue_items() < 1) {
			return false;
		}

		$sync_id = wp_generate_uuid4();
		$run_id  = Reg_Sync_DB::I()->create_sync_run([
			'sync_id' => $sync_id,
			'mode'    => 'Delta',
			'status'  => 'Building', // nothing to send until the batch is frozen below
		]);
		if (! $run_id) {
			return false;
		}

		$claimed = Reg_Sync_DB::I()->claim_queue_items($sync_id, $this->batch_size());

		$rows         = [];
		$no_row_ids   = [];
		foreach ($claimed as $q_item) {
			try {
				$item_rows = Reg_Product_Serializer::I()->rows_for_queue_item($q_item['product_id'], $q_item['variation_id'], $q_item['operation']);
			} catch (Throwable $e) {
				Reg_Sync_DB::I()->update_product_state([
					'product_id'          => (int) $q_item['product_id'],
					'variation_id'        => (int) $q_item['variation_id'],
					'external_product_id' => 'product:' . (int) $q_item['product_id'],
					'last_sync_status'    => 'Failed',
					'last_sync_id'        => $sync_id,
					'last_error_code'     => 'SERIALIZE_FAILED',
					'last_error_message'  => $e->getMessage(),
				]);
				$item_rows = [];
			}

			if (! $item_rows) {
				// Variable/grouped parents and the like: nothing to send, done with them.
				$no_row_ids[] = (int) $q_item['id'];
				continue;
			}
			foreach ($item_rows as $row) {
				$rows[] = $row;
			}
		}

		Reg_Sync_DB::I()->resolve_queue_items($sync_id, $no_row_ids);

		$run = Reg_Sync_DB::I()->get_sync_run_by_sync_id($sync_id);

		if (! $rows) {
			// Nothing worth a request (only variable parents, or another worker claimed the rows
			// first). Drop the empty run so it doesn't clutter the history.
			Reg_Sync_DB::I()->release_queue_items($sync_id, 'pending');
			Reg_Sync_DB::I()->delete_sync_run($sync_id);
			return (bool) $claimed;
		}

		// One queue row → at most one row, and we claimed ≤100, so this is always one batch.
		$this->freeze_batch($run, 1, array_slice($rows, 0, $this->batch_size()), true);

		Reg_Sync_DB::I()->update_sync_run($sync_id, [
			'status'        => 'InProgress',
			'total_items'   => min(count($rows), $this->batch_size()),
			'total_batches' => 1,
		]);

		return true;
	}

	// MARK: Send

	/**
	 * Send the run's next batch. "Next" means the lowest-numbered batch that isn't completed:
	 * if it's waiting on a retry timer, the whole run waits. Skipping ahead is exactly how the
	 * old engine produced SYNC_BATCH_OUT_OF_SEQUENCE.
	 */
	private function send_step(array $run)
	{
		$sync_id = $run['sync_id'];
		$batch   = Reg_Sync_DB::I()->get_next_batch_for_run($sync_id);

		if (! $batch) {
			// Everything is completed but the run wasn't closed (crash between the two writes).
			$this->finalize_run($run, null);
			return ['state' => 'progress'];
		}

		if (in_array($batch['status'], ['failed', 'abandoned'], true)) {
			$this->fail_run($run, $batch['last_error_code'] ?: 'BATCH_FAILED', $batch['last_error_message'] ?: 'A batch of this run failed.');
			return ['state' => 'progress'];
		}

		if ('retry_wait' === $batch['status'] && ! empty($batch['next_retry_at']) && $batch['next_retry_at'] > Reg_Sync_DB::now()) {
			return ['state' => 'wait', 'wait_until' => strtotime($batch['next_retry_at'] . ' UTC')];
		}

		// A batch still in 'processing' here was left behind by a crashed worker (we hold the
		// lease, so nobody else is sending it). Resending the frozen payload is safe: the server
		// replays it, or answers SYNC_BATCH_IN_PROGRESS while its own claim is fresh.
		Reg_Sync_DB::I()->update_batch((int) $batch['id'], [
			'status'        => 'processing',
			'sent_at'       => Reg_Sync_DB::now(),
			'attempt_count' => (int) $batch['attempt_count'] + 1,
		]);
		$batch['attempt_count'] = (int) $batch['attempt_count'] + 1;

		// The request can take up to 60s. Don't let max_execution_time kill us mid-flight.
		// Only ever RAISE the limit: 0 means unlimited (WP-CLI, some cron runners) and
		// set_time_limit(120) there would turn "forever" into "two minutes".
		$limit = (int) ini_get('max_execution_time');
		if ($limit > 0 && $limit < 120 && function_exists('set_time_limit')) {
			@set_time_limit(120);
		}

		$response = Reg_API::I()->submit_inventory_batch(Reg_API::I()->get_store_id(), $batch['payload']);

		if (is_wp_error($response)) {
			return $this->handle_batch_error($run, $batch, $response);
		}

		return $this->handle_batch_success($run, $batch, $response);
	}

	// MARK: Batch Success

	/**
	 * HTTP 200 means "batch processed", NOT "every row is fine". Every row result is read,
	 * stored in the ledger and counted. Retryable row errors are re-queued with backoff.
	 *
	 * Response `data` (LATEST doc): syncId, mode, batchNumber, isFinalBatch, isReplay,
	 * runStatus, results[], summary{status, createdCount, updatedCount, deactivatedCount,
	 * rejectedCount, ...}. A replay of a batch older than 180 days has empty results.
	 */
	private function handle_batch_success(array $run, array $batch, array $response)
	{
		$sync_id = $run['sync_id'];
		$data    = Reg_API::data($response);
		$results = isset($data['results']) && is_array($data['results']) ? $data['results'] : [];
		$now     = Reg_Sync_DB::now();

		$counts = [
			'created_count'     => 0,
			'updated_count'     => 0,
			'unchanged_count'   => 0,
			'deactivated_count' => 0,
			'skipped_count'     => 0,
			'stale_count'       => 0,
			'rejected_count'    => 0,
		];
		$status_to_counter = [
			'created'      => 'created_count',
			'updated'      => 'updated_count',
			'unchanged'    => 'unchanged_count',
			'deactivated'  => 'deactivated_count',
			'skipped'      => 'skipped_count',
			'staleignored' => 'stale_count',
		];

		$requeue = [];

		// What we sent, by identity, to judge activity of "Unchanged" rows (see below).
		$sent = [];
		$payload = json_decode((string) $batch['payload'], true);
		foreach ((array) ($payload['items'] ?? []) as $item) {
			$sent[($item['externalProductId'] ?? '') . '|' . ($item['externalVariationId'] ?? '')] = $item;
		}

		foreach ($results as $row) {
			$ext_prod_id = isset($row['externalProductId']) ? (string) $row['externalProductId'] : '';
			$ext_var_id  = ! empty($row['externalVariationId']) ? (string) $row['externalVariationId'] : null;
			$row_status  = isset($row['status']) ? (string) $row['status'] : 'Unknown';
			$errors      = isset($row['errors']) && is_array($row['errors']) ? $row['errors'] : [];
			$warnings    = isset($row['warnings']) && is_array($row['warnings']) ? $row['warnings'] : [];

			$prod_id = (int) preg_replace('/^product:/', '', $ext_prod_id);
			$var_id  = $ext_var_id ? (int) preg_replace('/^variation:/', '', $ext_var_id) : 0;

			$counter = $status_to_counter[strtolower($row_status)] ?? 'rejected_count'; // Rejected, Conflict (old servers), unknown
			$counts[$counter]++;

			$first_error   = $errors ? reset($errors) : null;
			$first_warning = $warnings ? reset($warnings) : null;
			$retryable     = false;
			foreach ($errors as $err) {
				if (is_array($err) && ! empty($err['isRetryable'])) {
					$retryable = true;
				}
			}

			$is_success = in_array(strtolower($row_status), ['created', 'updated', 'unchanged'], true) && ! $errors;
			$previous   = Reg_Sync_DB::I()->get_product_state($prod_id, $var_id);
			$retries    = $retryable ? ((int) ($previous['retry_count'] ?? 0) + 1) : 0;

			$state = [
				'product_id'            => $prod_id,
				'variation_id'          => $var_id,
				'external_product_id'   => $ext_prod_id,
				'external_variation_id' => $ext_var_id,
				'last_sync_status'      => $row_status,
				'last_sync_id'          => $sync_id,
				'last_item_id'          => isset($row['itemId']) ? (int) $row['itemId'] : null,
				'last_error_code'       => is_array($first_error) ? ($first_error['code'] ?? 'ROW_ERROR') : null,
				'last_error_message'    => is_array($first_error) ? ($first_error['message'] ?? null) : null,
				'last_error_retryable'  => $retryable ? 1 : 0,
				'last_warning_code'     => is_array($first_warning) ? ($first_warning['code'] ?? null) : null,
				'last_warning_message'  => is_array($first_warning) ? ($first_warning['message'] ?? null) : null,
				'retry_count'           => $retries,
			];

			// Is the item live on Regolith? The status alone doesn't say: Created / Updated are
			// written AND active, Deactivated / Skipped aren't, but Unchanged only means
			// "nothing changed", active or not. For Unchanged we judge from the row we sent,
			// with the same rules Regolith applies (published, purchasable, in stock, priced,
			// has an image). StaleIgnored / Rejected leave the previous value alone.
			$status_lc = strtolower($row_status);
			if (in_array($status_lc, ['created', 'updated'], true) && ! $errors) {
				$state['is_active'] = 1;
			} elseif (in_array($status_lc, ['deactivated', 'skipped'], true)) {
				$state['is_active'] = 0;
			} elseif ('unchanged' === $status_lc) {
				$state['is_active'] = $this->row_looks_active($sent[$ext_prod_id . '|' . ($ext_var_id ?? '')] ?? null) ? 1 : 0;
			}

			// Rejected with no explanation still needs to show up in the "action required" list.
			if ('rejected' === strtolower($row_status) && empty($state['last_error_code'])) {
				$state['last_error_code']    = 'ROW_REJECTED';
				$state['last_error_message'] = __('Item was rejected by Regolith.', 'regolith-integration');
			}

			if ($is_success) {
				$state['last_success_at'] = $now;
				if ($prod_id) {
					update_post_meta($var_id ?: $prod_id, '_regolith_last_sync', time());
				}
			}

			Reg_Sync_DB::I()->update_product_state($state);

			if ($retryable && $retries <= self::MAX_ROW_RETRIES && $prod_id) {
				$requeue[] = [$prod_id, $var_id, $retries];
			}
		}

		// 1. The batch is done.
		Reg_Sync_DB::I()->update_batch((int) $batch['id'], [
			'status'             => 'completed',
			'last_http_status'   => 200,
			'last_error_code'    => null,
			'last_error_message' => null,
			'completed_at'       => $now,
			'next_retry_at'      => null,
		]);

		Reg_Sync_DB::I()->increment_run_counters($sync_id, $counts + ['completed_batches' => 1]);

		// The run's "last error" came from this batch's earlier attempt and it went through
		// in the end: don't leave a scary note on a healthy run. (The batch row keeps nothing
		// either, the attempt count tells the story.)
		if (! empty($batch['last_error_code']) && $run['last_error_code'] === $batch['last_error_code']) {
			Reg_Sync_DB::I()->update_sync_run($sync_id, ['last_error_code' => null, 'last_error_message' => null]);
		}

		// 2. Delta: the queue rows behind this batch have landed. Rows edited again while in
		//    flight go back to pending instead of being dropped.
		if ('Delta' === $run['mode']) {
			Reg_Sync_DB::I()->resolve_queue_items($sync_id);
		}

		// 3. Retryable row errors go back on the queue with backoff (after step 2, so the
		//    resolve above doesn't delete them again).
		foreach ($requeue as [$prod_id, $var_id, $retries]) {
			Reg_Sync_DB::I()->enqueue_item($prod_id, $var_id, 'update', $this->backoff_seconds($retries + 1), $retries);
		}

		// 4. Final batch closes the run on the server; mirror it.
		if (! empty($batch['is_final'])) {
			$server_status = $data['runStatus'] ?? ($data['summary']['status'] ?? null);
			$this->finalize_run(Reg_Sync_DB::I()->get_sync_run_by_sync_id($sync_id), $server_status);
		}

		return ['state' => 'progress'];
	}

	/**
	 * Would Regolith keep this row active? Mirrors the LATEST doc's "Inactive when" list for
	 * the parts we can see locally. Category resolution and image import can still make it
	 * inactive server-side, those come back as errors / Deactivated and win.
	 *
	 * @param array|null $item A row as we sent it.
	 */
	private function row_looks_active($item)
	{
		if (! is_array($item)) {
			return false;
		}
		$price = ! empty($item['saleIsActive']) && null !== ($item['salePrice'] ?? null) ? $item['salePrice'] : ($item['regularPrice'] ?? null);

		return ! empty($item['isPublished'])
			&& ! empty($item['isPurchasable'])
			&& ! empty($item['isInStock'])
			&& empty($item['isDeleted'])
			&& null !== $price && (float) $price > 0
			&& ! empty($item['imageUrl']);
	}

	// MARK: Batch Errors

	/**
	 * Decide what a failed batch request means.
	 *
	 * | What                                                 | Then                         |
	 * |------------------------------------------------------|------------------------------|
	 * | transport error, 429, 5xx, SYNC_BATCH_IN_PROGRESS,   | retry with backoff           |
	 * | CONCURRENT_REQUEST, SYNC_RATE_LIMITED                | (Retry-After when given)     |
	 * | FULL_SYNC_ALREADY_RUNNING (someone else's syncId)    | abandon that one, retry now  |
	 * | SYNC_RUN_COMPLETE                                    | server closed it: finalize   |
	 * | store / key / vendor broken (BLOCKING_CODES, 401/403)| block sync, keep the batch   |
	 * | anything else (400s, conflicts, out of sequence...)  | fail the run                 |
	 */
	private function handle_batch_error(array $run, array $batch, WP_Error $error)
	{
		$info      = Reg_API::I()->extract_error_info($error);
		$code      = $info['code'];
		$http_code = $info['http_code'];
		$attempts  = (int) $batch['attempt_count'];

		$this->record_batch_error($batch, $info);

		// Store-level problem: stop everything, keep the batch exactly where it is so the run
		// resumes untouched once someone fixes the connection.
		if (in_array($code, self::BLOCKING_CODES, true) || in_array($http_code, [401, 403], true) || (404 === $http_code && 'STORE_NOT_FOUND' === $code)) {
			Reg_Sync_DB::I()->update_batch((int) $batch['id'], ['status' => 'retry_wait', 'next_retry_at' => null]);
			// A bare 401/403 has no CODE (non-JSON body). Store it as a key problem so the
			// store owner reads "API key not accepted", not "something went wrong".
			$block_code = in_array($http_code, [401, 403], true) && ! isset(Reg_Messages::all()[$code]) ? 'regolith_auth_invalid' : $code;
			$this->block($block_code, $info['message']);
			return ['state' => 'blocked'];
		}

		if ('SYNC_RUN_COMPLETE' === $code) {
			// Usually: the store was unlinked (open runs get abandoned server-side), or we lost
			// the response of the final batch. Ask the server what really happened.
			$this->finalize_run($run, null);
			return ['state' => 'progress'];
		}

		$delay = null;

		if ('FULL_SYNC_ALREADY_RUNNING' === $code) {
			$other = is_array($info['data']) ? ($info['data']['syncId'] ?? null) : null;
			$local = $other ? Reg_Sync_DB::I()->get_sync_run_by_sync_id($other) : null;
			$still_ours_and_live = $local && in_array($local['status'], ['Building', 'InProgress'], true);

			if ($other && $other !== $run['sync_id'] && ! $still_ours_and_live) {
				// A leftover from a crash, a nuke of the local tables, or the old engine. One store
				// per vendor and only this plugin writes to it, so it's ours and nobody is working
				// it. Abandon it (no reconciliation happens) and go again right away.
				$note = $this->abandon_on_server($other);
				Reg_Sync_DB::I()->update_sync_run($run['sync_id'], [
					'last_error_code'    => 'STALE_FULL_RUN_ABANDONED',
					'last_error_message' => "Regolith still had Full run {$other} open. {$note}",
				]);
				$delay = 0;
			}
		}

		$retryable = $info['transport']
			|| 429 === $http_code
			|| ($http_code >= 500)
			|| in_array($code, self::RETRYABLE_CODES, true)
			|| 'FULL_SYNC_ALREADY_RUNNING' === $code;

		if (! $retryable) {
			$this->fail_run($run, $code, $info['message'], $batch);
			return ['state' => 'progress'];
		}

		$in_progress  = 'SYNC_BATCH_IN_PROGRESS' === $code;
		$max_attempts = $in_progress ? self::MAX_IN_PROGRESS_ATTEMPTS : self::MAX_BATCH_ATTEMPTS;

		if ($attempts >= $max_attempts) {
			$this->fail_run($run, $code, sprintf('Gave up after %d attempts. Last error: %s', $attempts, $info['message']), $batch);
			return ['state' => 'progress'];
		}

		if (null === $delay) {
			$delay = $info['retry_after'] ? (int) $info['retry_after'] : $this->backoff_seconds($attempts);
			if ($in_progress) {
				$delay = min(60, $delay);
			}
		}

		Reg_Sync_DB::I()->update_batch((int) $batch['id'], [
			'status'        => 'retry_wait',
			'next_retry_at' => Reg_Sync_DB::now($delay),
		]);

		return $delay > 0
			? ['state' => 'wait', 'wait_until' => time() + $delay]
			: ['state' => 'progress'];
	}

	private function record_batch_error(array $batch, array $info)
	{
		Reg_Sync_DB::I()->update_batch((int) $batch['id'], [
			'last_http_status'   => $info['http_code'],
			'last_error_code'    => mb_substr((string) $info['code'], 0, 100),
			'last_error_message' => $info['message'],
		]);
		Reg_Sync_DB::I()->update_sync_run($batch['sync_id'], [
			'last_error_code'    => mb_substr((string) $info['code'], 0, 100),
			'last_error_message' => $info['message'],
		]);
	}

	/**
	 * Rows per inventory batch, see DEFAULT_BATCH_SIZE.
	 *
	 * Only read when a batch is being frozen, so changing it never touches batches that
	 * already exist (they'd stop being identical replays).
	 */
	public function batch_size()
	{
		/**
		 * Filter the number of rows per inventory batch (1..100).
		 *
		 * @param int $size
		 */
		$size = (int) apply_filters('regolith_sync_batch_size', self::DEFAULT_BATCH_SIZE);
		return max(1, min(self::BATCH_SIZE, $size));
	}

	/**
	 * Exponential backoff with jitter: 5s, 10s, 20s ... capped at 15 minutes.
	 */
	private function backoff_seconds($attempt)
	{
		$attempt = max(1, (int) $attempt);
		return (int) min(900, 5 * pow(2, min($attempt - 1, 10))) + wp_rand(0, 3);
	}

	// MARK: Run End

	/**
	 * Close a run locally, mirroring the server's verdict.
	 *
	 * Server statuses (LATEST doc): Completed, CompletedWithErrors (any rejected row or row
	 * error), Abandoned. `Failed` exists in the enum but is never set. Note: a closed Full run
	 * reconciles in BOTH Completed and CompletedWithErrors, the Aug "Final Spec" got that wrong.
	 *
	 * @param array       $run
	 * @param string|null $server_status Known status, or null to ask GET sync-runs/{id}.
	 */
	private function finalize_run($run, $server_status)
	{
		if (! $run) {
			return;
		}
		$sync_id = $run['sync_id'];

		if (! $server_status) {
			$remote = Reg_API::I()->get_sync_run_status(Reg_API::I()->get_store_id(), $sync_id);
			if (! is_wp_error($remote)) {
				$remote_data   = Reg_API::data($remote);
				$server_status = $remote_data['status'] ?? null;
			}
		}

		$known  = ['Completed', 'CompletedWithErrors', 'Abandoned', 'Failed', 'InProgress'];
		$status = in_array($server_status, $known, true) ? $server_status : null;

		if ('InProgress' === $status) {
			// Server still has it open but we think we're done. Shouldn't happen; leave the run
			// active, the next step will find no batch and ask again.
			return;
		}

		if (! $status) {
			// Couldn't ask. Best local guess, flagged so it's obvious it isn't the server's word.
			$fresh  = Reg_Sync_DB::I()->get_sync_run_by_sync_id($sync_id);
			$status = ((int) ($fresh['rejected_count'] ?? 0) > 0) ? 'CompletedWithErrors' : 'Completed';
			$server_status = 'Unknown';
		}

		Reg_Sync_DB::I()->close_open_batches($sync_id, 'abandoned');

		if ('Delta' === $run['mode']) {
			// Normally already resolved per batch. This catches a run closed server-side
			// (unlink) before its batch landed: those changes still need to go out.
			Reg_Sync_DB::I()->release_queue_items($sync_id, 'pending');
		}

		Reg_Sync_DB::I()->update_sync_run($sync_id, [
			'status'        => $status,
			'server_status' => $server_status,
			'build_buffer'  => null,
			'completed_at'  => Reg_Sync_DB::now(),
		]);
	}

	/**
	 * Give up on a run. For a Full run that reached the server, abandon it there too, or the
	 * store stays locked with FULL_SYNC_ALREADY_RUNNING until someone notices.
	 */
	private function fail_run(array $run, $code, $message, $batch = null)
	{
		$sync_id = $run['sync_id'];

		if ($batch) {
			Reg_Sync_DB::I()->update_batch((int) $batch['id'], ['status' => 'failed', 'next_retry_at' => null]);
		}

		$note = '';
		if ('Full' === $run['mode'] && Reg_Sync_DB::I()->run_has_sent_batches($sync_id)) {
			$note = ' ' . $this->abandon_on_server($sync_id);
		}

		Reg_Sync_DB::I()->close_open_batches($sync_id, 'abandoned');

		if ('Delta' === $run['mode']) {
			Reg_Sync_DB::I()->release_queue_items($sync_id, 'failed', $code, $message);
		}

		Reg_Sync_DB::I()->increment_run_counters($sync_id, ['failed_batches' => $batch ? 1 : 0]);
		Reg_Sync_DB::I()->update_sync_run($sync_id, [
			'status'             => 'Failed',
			'build_buffer'       => null,
			'last_error_code'    => mb_substr((string) $code, 0, 100),
			'last_error_message' => $message . $note,
			'completed_at'       => Reg_Sync_DB::now(),
		]);
	}

	// MARK: Blocking

	/**
	 * Stop all sending until a human fixes the store/key/vendor setup. Cleared by a successful
	 * "Test Connection" or the Resume button.
	 */
	public function block($code, $message)
	{
		update_option('regolith_sync_blocked', [
			'code'    => (string) $code,
			'message' => (string) $message,
			'at'      => Reg_Sync_DB::now(),
		], false);
	}

	public function unblock()
	{
		delete_option('regolith_sync_blocked');
		Reg_Sync_Queue::I()->schedule_work();
	}

	/**
	 * @return array|null { code, message, at }
	 */
	public function get_block()
	{
		$blocked = get_option('regolith_sync_blocked');
		return is_array($blocked) && ! empty($blocked['code']) ? $blocked : null;
	}

	// MARK: Status

	/**
	 * Everything the admin page needs, in one array (also returned by the AJAX tick).
	 */
	public function get_status()
	{
		$db     = Reg_Sync_DB::I();
		$active = [];

		foreach ($db->get_active_runs() as $run) {
			$active[] = [
				'sync_id'           => $run['sync_id'],
				'mode'              => $run['mode'],
				'status'            => $run['status'],
				'total_items'       => (int) $run['total_items'],
				'total_batches'     => (int) $run['total_batches'],
				'completed_batches' => (int) $run['completed_batches'],
				'last_error'        => $run['last_error_code'] ? $run['last_error_code'] . ': ' . $run['last_error_message'] : '',
				'next_batch'        => $this->describe_next_batch($run),
			];
		}

		return [
			'store_id'   => Reg_API::I()->get_store_id(),
			'blocked'    => $this->get_block(),
			'active'     => $active,
			'queue'      => $db->get_queue_counts(),
			'totals'     => $db->get_sync_totals(),
			'rejected'   => $db->count_rejected_products(),
			'last_full'  => $db->get_latest_sync_run('Full', ['Completed', 'CompletedWithErrors']),
			'last_delta' => $db->get_latest_sync_run('Delta', ['Completed', 'CompletedWithErrors']),
		];
	}

	private function describe_next_batch(array $run)
	{
		if ('Building' === $run['status']) {
			return 'Building snapshot (after product #' . (int) $run['build_cursor'] . ')';
		}
		$batch = Reg_Sync_DB::I()->get_next_batch_for_run($run['sync_id']);
		if (! $batch) {
			return '';
		}
		$text = 'Batch ' . (int) $batch['batch_number'] . ': ' . $batch['status'];
		if ('retry_wait' === $batch['status'] && $batch['next_retry_at']) {
			$text .= ' until ' . get_date_from_gmt($batch['next_retry_at'], 'H:i:s');
		}
		if ($batch['last_error_code']) {
			$text .= ' (last: ' . $batch['last_error_code'] . ')';
		}
		return $text;
	}
}
