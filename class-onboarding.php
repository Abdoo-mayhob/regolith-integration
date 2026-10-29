<?php

/**
 * Regolith Onboarding State
 *
 * The category half of the integration, kept in sync AFTER the first onboarding too:
 * - Category snapshot     PUT .../categories (complete list of WooCommerce categories)
 * - Membership snapshot   PUT .../product-category-memberships, batched, resumable
 * - Auto refresh          when categories or product category assignments change, both
 *                         snapshots are re-sent (debounced, via Action Scheduler)
 *
 * Why the refresh matters: Regolith resolves an inventory row's category from the latest
 * COMPLETE membership snapshot. A product moved to another category after onboarding keeps
 * resolving to the old one (or gets rejected) until a new snapshot completes.
 *
 * Membership snapshot rules we follow (LATEST doc):
 * - parent products only, max 100 per batch, batches 1..n, final batch makes it current
 * - one open snapshot per store; an open one we don't know about is ours from an earlier
 *   attempt, so we abandon it and start over
 * - an identical replay is fine (200, isReplay), a changed one is SNAPSHOT_BATCH_CONFLICT,
 *   so the last unacknowledged batch is stored and resent byte-for-byte
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Onboarding
{
	const REFRESH_HOOK = 'regolith_onboarding_refresh';

	/**
	 * Products per membership batch (API max is 100).
	 */
	const MEMBERSHIP_BATCH_SIZE = 100;

	/**
	 * Debounce for the automatic refresh: a bulk edit of 300 products should cause one
	 * snapshot, not 300.
	 */
	const REFRESH_DELAY = 300;

	/**
	 * Onboarding statuses where we keep Regolith up to date on our own. Before that the
	 * admin drives every step by hand on the settings page.
	 */
	const AUTO_REFRESH_STATUSES = ['ReadyForInitialSync', 'Active', 'AttentionRequired'];

	const STATE_OPTION = 'regolith_pcm_state';

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
		add_action(self::REFRESH_HOOK, [$this, 'run_refresh']);
	}

	// MARK: Dirty Flags

	/**
	 * Note that Regolith's copy of our categories / memberships is out of date, and schedule
	 * the automatic refresh when the store is past onboarding.
	 *
	 * @param string $what 'categories' | 'memberships'. Categories imply memberships.
	 */
	public function mark_dirty($what)
	{
		if ('categories' === $what) {
			update_option('regolith_categories_dirty', 1, false);
		}
		update_option('regolith_memberships_dirty', 1, false);

		if (! $this->auto_refresh_enabled() || ! function_exists('as_schedule_single_action')) {
			return;
		}

		if (! as_has_scheduled_action(self::REFRESH_HOOK, [], Reg_Sync_Queue::GROUP)) {
			as_schedule_single_action(time() + self::REFRESH_DELAY, self::REFRESH_HOOK, [], Reg_Sync_Queue::GROUP, true);
		}
	}

	public function auto_refresh_enabled()
	{
		return Reg_API::I()->get_store_id()
			&& in_array(get_option('regolith_onboarding_status', ''), self::AUTO_REFRESH_STATUSES, true);
	}

	public function is_dirty($what)
	{
		return (bool) get_option('regolith_' . $what . '_dirty', 0);
	}

	/**
	 * Action Scheduler callback: categories first (memberships may reference new ones), then
	 * the membership snapshot. Reschedules itself if the snapshot didn't finish in time.
	 */
	public function run_refresh()
	{
		if ($this->is_dirty('categories')) {
			$res = Reg_API::I()->sync_category_snapshot();
			if (is_wp_error($res)) {
				// Try again later, memberships against stale categories would just fail.
				as_schedule_single_action(time() + self::REFRESH_DELAY, self::REFRESH_HOOK, [], Reg_Sync_Queue::GROUP, true);
				return;
			}
		}

		$state = $this->get_membership_state();
		if ('running' !== $state['status'] && $this->is_dirty('memberships')) {
			$this->start_membership_snapshot();
		}

		$result = $this->run_membership_snapshot(20);
		if (in_array($result['status'], ['running', 'wait'], true)) {
			as_schedule_single_action(time() + max(5, (int) ($result['retry_in'] ?? 5)), self::REFRESH_HOOK, [], Reg_Sync_Queue::GROUP, true);
		}
	}

	// MARK: Membership State

	/**
	 * @return array {
	 *     status        idle | running | complete | failed
	 *     snapshot_id   UUID shared by every batch of this snapshot
	 *     next_batch    batch number to send next
	 *     cursor        last parent product ID that's been acknowledged
	 *     pending       frozen JSON of the batch that's out but not acknowledged (or null)
	 *     pending_cursor cursor value once `pending` is acknowledged
	 *     pending_final  whether `pending` is the final batch
	 *     sent          products acknowledged so far
	 *     total         products in the catalog when the snapshot started (progress only)
	 *     retry_at      unix time before which we shouldn't resend (rate limits etc.)
	 *     last_error    'CODE: message' of the last failure
	 *     restarts      how many times this snapshot started over after a conflict
	 * }
	 */
	public function get_membership_state()
	{
		$defaults = [
			'status'         => 'idle',
			'snapshot_id'    => '',
			'next_batch'     => 1,
			'cursor'         => 0,
			'pending'        => null,
			'pending_cursor' => 0,
			'pending_final'  => false,
			'sent'           => 0,
			'total'          => 0,
			'retry_at'       => 0,
			'last_error'     => '',
			'restarts'       => 0,
			'started_at'     => '',
			'completed_at'   => '',
		];
		$state = get_option(self::STATE_OPTION, []);
		return wp_parse_args(is_array($state) ? $state : [], $defaults);
	}

	private function save_membership_state(array $state)
	{
		update_option(self::STATE_OPTION, $state, false);
	}

	/**
	 * Start a brand new snapshot from the first product. A snapshot must be COMPLETE to be
	 * useful, there's no such thing as "send only the new ones" (that was the old cursor bug).
	 * An open snapshot of ours is abandoned first.
	 */
	public function start_membership_snapshot($restarts = 0)
	{
		$old = $this->get_membership_state();
		// Abandon is repeat-safe (404 when the server never saw it, 409 when it's complete),
		// so no need to be clever about whether batch 1 actually landed.
		if ('running' === $old['status'] && $old['snapshot_id']) {
			Reg_API::I()->abandon_membership_snapshot($old['snapshot_id']);
		}

		$state = $this->get_membership_state();
		$state = array_merge($state, [
			'status'         => 'running',
			'snapshot_id'    => wp_generate_uuid4(),
			'next_batch'     => 1,
			'cursor'         => 0,
			'pending'        => null,
			'pending_cursor' => 0,
			'pending_final'  => false,
			'sent'           => 0,
			'total'          => $this->count_parent_products(),
			'retry_at'       => 0,
			'last_error'     => '',
			'restarts'       => (int) $restarts,
			'started_at'     => Reg_Sync_DB::now(),
			'completed_at'   => '',
		]);
		$this->save_membership_state($state);

		return $state;
	}

	/**
	 * Push membership batches until done, out of budget, or something needs waiting.
	 *
	 * @param  int $budget Seconds.
	 * @return array { status: running|wait|complete|failed|idle, state: array, retry_in?: int, message?: string }
	 */
	public function run_membership_snapshot($budget = 15)
	{
		$deadline = microtime(true) + max(1, (int) $budget);

		do {
			$result = $this->membership_step();
			if ('running' !== $result['status']) {
				return $result;
			}
		} while (microtime(true) < $deadline);

		return $result;
	}

	/**
	 * Send (or resend) one membership batch.
	 */
	private function membership_step()
	{
		$state = $this->get_membership_state();

		if ('running' !== $state['status']) {
			return ['status' => $state['status'], 'state' => $state];
		}

		if ($state['retry_at'] > time()) {
			return ['status' => 'wait', 'state' => $state, 'retry_in' => $state['retry_at'] - time()];
		}

		// Freeze the next batch unless one is already out waiting for an answer.
		if (empty($state['pending'])) {
			[$products, $last_id, $is_final] = $this->build_membership_products((int) $state['cursor']);

			$state['pending'] = wp_json_encode([
				'snapshotId'   => $state['snapshot_id'],
				'batchNumber'  => (int) $state['next_batch'],
				'isFinalBatch' => $is_final,
				'products'     => $products,
			]);
			$state['pending_cursor'] = $last_id;
			$state['pending_final']  = $is_final;
			$state['pending_count']  = count($products);
			$this->save_membership_state($state);
		}

		$payload  = json_decode($state['pending'], true);
		$response = Reg_API::I()->put_membership_batch($payload);

		if (! is_wp_error($response)) {
			$state['cursor']     = (int) $state['pending_cursor'];
			$state['sent']      += (int) ($state['pending_count'] ?? 0);
			$state['next_batch'] = (int) $state['next_batch'] + 1;
			$state['pending']    = null;
			$state['last_error'] = '';
			$state['category_retry'] = 0;

			if (! empty($state['pending_final'])) {
				$state['status']       = 'complete';
				$state['completed_at'] = Reg_Sync_DB::now();
				update_option('regolith_memberships_dirty', 0, false);
			}

			$this->save_membership_state($state);
			return ['status' => 'complete' === $state['status'] ? 'complete' : 'running', 'state' => $state];
		}

		return $this->handle_membership_error($state, $response);
	}

	/**
	 * What a failed membership batch means. Mirrors the inventory rules, adapted:
	 * - retryable (transport, 429, 5xx, CONCURRENT_REQUEST): resend the same batch later
	 * - MEMBERSHIP_SNAPSHOT_IN_PROGRESS with another snapshotId: ours, left over. Abandon it.
	 * - out of sequence / conflict / already complete / abandoned: our view and the server's
	 *   disagree. Start over from scratch (once, twice... then give up and show the error).
	 * - MEMBERSHIP_CATEGORY_UNKNOWN: categories changed since the last category snapshot.
	 *   Push categories, then resend.
	 */
	private function handle_membership_error(array $state, WP_Error $error)
	{
		$info = Reg_API::I()->extract_error_info($error);
		$code = $info['code'];
		$state['last_error'] = $code . ': ' . $info['message'];

		if ('MEMBERSHIP_SNAPSHOT_IN_PROGRESS' === $code) {
			$other = is_array($info['data']) ? ($info['data']['snapshotId'] ?? null) : null;
			if ($other && $other !== $state['snapshot_id']) {
				Reg_API::I()->abandon_membership_snapshot($other);
				$this->save_membership_state($state);
				return ['status' => 'running', 'state' => $state];
			}
			// Our own snapshot is "in progress" because another request of ours holds it.
			return $this->membership_retry_later($state, $info['retry_after'] ?: 10);
		}

		$retryable = $info['transport'] || 429 === $info['http_code'] || $info['http_code'] >= 500 || in_array($code, ['CONCURRENT_REQUEST'], true);
		if ($retryable) {
			return $this->membership_retry_later($state, $info['retry_after'] ?: 15);
		}

		if ('MEMBERSHIP_CATEGORY_UNKNOWN' === $code && empty($state['category_retry'])) {
			$cats = Reg_API::I()->sync_category_snapshot();
			if (! is_wp_error($cats)) {
				// The frozen batch is still valid, the server just didn't know the categories yet.
				$state['category_retry'] = 1;
				$this->save_membership_state($state);
				return ['status' => 'running', 'state' => $state];
			}
		}

		$restartable = ['SNAPSHOT_BATCH_OUT_OF_SEQUENCE', 'SNAPSHOT_BATCH_CONFLICT', 'MEMBERSHIP_SNAPSHOT_COMPLETE', 'MEMBERSHIP_SNAPSHOT_ABANDONED'];
		if (in_array($code, $restartable, true) && (int) $state['restarts'] < 2) {
			$fresh = $this->start_membership_snapshot((int) $state['restarts'] + 1);
			$fresh['last_error'] = 'Restarted after ' . $state['last_error'];
			$this->save_membership_state($fresh);
			return ['status' => 'running', 'state' => $fresh];
		}

		$state['status'] = 'failed';
		$this->save_membership_state($state);

		return ['status' => 'failed', 'state' => $state, 'message' => $state['last_error']];
	}

	private function membership_retry_later(array $state, $seconds)
	{
		$state['retry_at'] = time() + max(1, (int) $seconds);
		$this->save_membership_state($state);
		return ['status' => 'wait', 'state' => $state, 'retry_in' => (int) $seconds];
	}

	/**
	 * Next page of parent products after $cursor, by ID. ID order is stable and unique, unlike
	 * post_date (every demo product shares the same second, which is how the old date cursor
	 * skipped products at batch boundaries).
	 *
	 * Same statuses as the Full inventory snapshot, so every product we send inventory for has
	 * a membership entry (drafts included, they're sent as unpublished).
	 *
	 * @return array [ products[], last_id, is_final ]
	 */
	private function build_membership_products($cursor)
	{
		global $wpdb;

		$statuses = Reg_Product_Serializer::CATALOG_STATUSES;
		// One extra row tells us whether this is the last batch, so the final batch is never empty.
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ")
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			...array_merge($statuses, [(int) $cursor, self::MEMBERSHIP_BATCH_SIZE + 1])
		));

		$is_final = count($ids) <= self::MEMBERSHIP_BATCH_SIZE;
		$ids      = array_slice($ids, 0, self::MEMBERSHIP_BATCH_SIZE);

		$products = [];
		$last_id  = (int) $cursor;
		foreach ($ids as $post_id) {
			$post_id  = (int) $post_id;
			$last_id  = $post_id;
			$name     = trim(wp_strip_all_tags(html_entity_decode((string) get_post_field('post_title', $post_id), ENT_QUOTES, 'UTF-8')));

			$products[] = [
				// Must match the inventory rows' externalProductId exactly, it's the join key.
				'externalProductId'   => 'product:' . $post_id,
				'name'                => mb_substr('' !== $name ? $name : 'Product #' . $post_id, 0, 200),
				'categoryExternalIds' => Reg_Product_Serializer::I()->get_product_category_ids($post_id),
			];
		}

		return [$products, $last_id, $is_final];
	}

	private function count_parent_products()
	{
		global $wpdb;
		$statuses = Reg_Product_Serializer::CATALOG_STATUSES;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')',
			...$statuses
		));
	}
}
