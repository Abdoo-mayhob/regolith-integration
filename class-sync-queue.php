<?php

/**
 * Regolith Sync Queue & WooCommerce Event Listener
 *
 * Two jobs:
 * 1. Listen for WooCommerce lifecycle events and put the affected product / variation on the
 *    durable queue (Reg_Sync_DB::enqueue_item). Listeners only ENQUEUE, they never call the
 *    API, so a product save stays fast and nothing is lost if Regolith is down.
 * 2. Drive the engine with Action Scheduler (ships with WooCommerce, so it's always there):
 *    - `regolith_sync_tick`  recurring every 60s: start a delta if the queue has work, then work.
 *    - `regolith_sync_work`  one-off "keep going" action when a run still has work left.
 *    Action Scheduler gives us claimed jobs (no double runs), a log of every run in
 *    Tools > Scheduled Actions (group "regolith") and recovery from timed-out requests,
 *    all things the old WP-Cron + transient setup had to fake.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Sync_Queue
{
	const GROUP     = 'regolith';
	const TICK_HOOK = 'regolith_sync_tick';
	const WORK_HOOK = 'regolith_sync_work';

	/**
	 * Seconds between ticks. Edits are picked up faster than that anyway: an enqueue schedules
	 * a work action a few seconds out (see maybe_kick_on_shutdown()).
	 */
	const TICK_INTERVAL = 60;

	/**
	 * Old WP-Cron hook from the first engine. Unscheduled on sight.
	 */
	const LEGACY_CRON_HOOK = 'regolith_sync_queue_worker';

	/**
	 * Set when something was enqueued during this request.
	 *
	 * @var bool
	 */
	private $enqueued = false;

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
		// MARK: Product Hooks
		// CRUD saves (admin edit, quick edit, REST, imports) all end in one of these.
		add_action('woocommerce_new_product', [$this, 'on_product_updated'], 10, 1);
		add_action('woocommerce_update_product', [$this, 'on_product_updated'], 10, 1);

		// Variations
		add_action('woocommerce_new_product_variation', [$this, 'on_variation_updated'], 10, 1);
		add_action('woocommerce_update_product_variation', [$this, 'on_variation_updated'], 10, 1);
		add_action('woocommerce_save_product_variation', [$this, 'on_variation_updated'], 10, 1);

		// Stock & Status. Orders change stock with direct SQL (wc_update_product_stock), which
		// does NOT fire woocommerce_update_product, only these.
		add_action('woocommerce_product_set_stock', [$this, 'on_stock_changed'], 10, 1);
		add_action('woocommerce_variation_set_stock', [$this, 'on_stock_changed'], 10, 1);
		add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_changed'], 10, 3);
		add_action('woocommerce_variation_set_stock_status', [$this, 'on_stock_status_changed'], 10, 3);
		add_action('transition_post_status', [$this, 'on_post_status_transition'], 10, 3);

		// Deletions / Trashing (products and variations)
		add_action('trashed_post', [$this, 'on_post_trashed'], 10, 1);
		add_action('untrashed_post', [$this, 'on_post_untrashed'], 10, 1);
		add_action('before_delete_post', [$this, 'on_post_deleted'], 10, 1);

		// Taxonomy assignment changes (Categories / Brands / attribute terms)
		add_action('set_object_terms', [$this, 'on_terms_changed'], 10, 6);

		// A term itself changed (brand renamed, attribute value renamed, category edited).
		add_action('edited_term', [$this, 'on_term_edited'], 10, 3);
		add_action('created_term', [$this, 'on_term_created_or_deleted'], 10, 3);
		add_action('delete_term', [$this, 'on_term_created_or_deleted'], 10, 3);

		// MARK: Scheduler Hooks
		add_action(self::TICK_HOOK, [$this, 'run_tick']);
		add_action(self::WORK_HOOK, [$this, 'run_work']);
		add_action('init', [$this, 'ensure_schedule'], 20);
		add_action('shutdown', [$this, 'maybe_kick_on_shutdown']);
	}

	// MARK: Scheduling

	/**
	 * Make sure the recurring tick exists and the legacy WP-Cron event is gone.
	 * as_has_scheduled_action() is a DB query, so it's throttled to once per 10 minutes and
	 * only runs in admin / cron / Action Scheduler requests, never on storefront page views.
	 */
	public function ensure_schedule($force = false)
	{
		if (! function_exists('as_has_scheduled_action')) {
			return;
		}
		if (! $force && ! (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
			return;
		}
		if (! $force && get_transient('regolith_schedule_checked')) {
			return;
		}
		set_transient('regolith_schedule_checked', 1, 10 * MINUTE_IN_SECONDS);

		if (wp_next_scheduled(self::LEGACY_CRON_HOOK)) {
			wp_clear_scheduled_hook(self::LEGACY_CRON_HOOK);
		}

		if (! as_has_scheduled_action(self::TICK_HOOK, [], self::GROUP)) {
			as_schedule_recurring_action(time() + 30, self::TICK_INTERVAL, self::TICK_HOOK, [], self::GROUP, true);
		}
	}

	/**
	 * Ask Action Scheduler to run the engine soon. Safe to call often: there's only ever one
	 * pending work action, moved earlier when needed.
	 *
	 * @param int $delay Seconds from now.
	 */
	public function schedule_work($delay = 0)
	{
		if (! function_exists('as_next_scheduled_action')) {
			return;
		}

		$when = time() + max(0, (int) $delay);
		$next = as_next_scheduled_action(self::WORK_HOOK, [], self::GROUP);

		if (true === $next) {
			return; // one is running right now, it reschedules itself when it's done
		}
		if ($next && $next <= $when) {
			return; // already due earlier
		}
		if ($next) {
			as_unschedule_all_actions(self::WORK_HOOK, [], self::GROUP);
		}

		if ($delay <= 0) {
			as_enqueue_async_action(self::WORK_HOOK, [], self::GROUP, true);
		} else {
			as_schedule_single_action($when, self::WORK_HOOK, [], self::GROUP, true);
		}
	}

	public function run_tick()
	{
		$this->run_work();
	}

	/**
	 * The Action Scheduler side of the engine. Works for a while, then makes sure it gets
	 * called again when there's more to do (right away, or when the next retry is due).
	 */
	public function run_work()
	{
		$result = Reg_Sync_Service::I()->work(Reg_Sync_Service::WORK_BUDGET);

		if ('progress' === $result['state']) {
			$this->schedule_work(0);
		} elseif ('wait' === $result['state'] && ! empty($result['wait_until'])) {
			$this->schedule_work(max(1, $result['wait_until'] - time()));
		} elseif ('busy' === $result['state']) {
			$this->schedule_work(15);
		}
		// idle / blocked: the minute tick is enough.
	}

	/**
	 * Something was enqueued during this request: have it synced within seconds instead of
	 * waiting for the next tick. Once per request, however many hooks fired.
	 */
	public function maybe_kick_on_shutdown()
	{
		if ($this->enqueued) {
			$this->schedule_work(5);
		}
	}

	// MARK: Enqueue Helpers

	private function enqueue($product_id, $variation_id = 0, $operation = 'update')
	{
		if (! $product_id) {
			return;
		}
		Reg_Sync_DB::I()->enqueue_item((int) $product_id, (int) $variation_id, $operation);
		$this->enqueued = true;
	}

	/**
	 * Helper to enqueue parent product and its variations if variable.
	 * A parent change (title, category, brand, image, attributes) changes every variation row,
	 * so they all go.
	 */
	public function enqueue_product_and_children($product_id, $operation = 'update')
	{
		$product_id = (int) $product_id;
		if (! $product_id) {
			return;
		}

		$this->enqueue($product_id, 0, $operation);

		// All variations, any status, straight from the DB: get_children() skips some
		// statuses, and when a product is being deleted we want every last one.
		$children = get_posts([
			'post_type'      => 'product_variation',
			'post_parent'    => $product_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]);
		foreach ($children as $child_id) {
			$this->enqueue($product_id, (int) $child_id, $operation);
		}
	}

	/**
	 * Queue one variation. Needs the parent id, which the queue stores so a deletion row can
	 * still be built after the variation post is gone.
	 */
	private function enqueue_variation($variation_id, $operation = 'update', $parent_id = 0)
	{
		$variation_id = absint($variation_id);
		if (! $variation_id) {
			return;
		}
		if (! $parent_id) {
			$parent_id = wp_get_post_parent_id($variation_id);
		}
		if ($parent_id) {
			$this->enqueue($parent_id, $variation_id, $operation);
		}
	}

	// MARK: Event Handlers

	/**
	 * Hooked to product updates/creation.
	 */
	public function on_product_updated($product_or_id)
	{
		$product_id = is_numeric($product_or_id) ? (int) $product_or_id : ($product_or_id instanceof WC_Product ? $product_or_id->get_id() : 0);
		if (! $product_id) {
			return;
		}

		if (wp_is_post_autosave($product_id) || wp_is_post_revision($product_id)) {
			return;
		}

		$this->enqueue_product_and_children($product_id, 'update');
	}

	public function on_variation_updated($variation_id)
	{
		$this->enqueue_variation($variation_id, 'update');
	}

	public function on_stock_changed($product)
	{
		if ($product instanceof WC_Product) {
			if ($product->is_type('variation')) {
				$this->enqueue_variation($product->get_id(), 'update', $product->get_parent_id());
			} else {
				$this->enqueue_product_and_children($product->get_id(), 'update');
			}
		}
	}

	public function on_stock_status_changed($product_id, $stock_status = '', $product = null)
	{
		if ($product instanceof WC_Product) {
			$this->on_stock_changed($product);
			return;
		}
		$post_type = get_post_type($product_id);
		if ('product_variation' === $post_type) {
			$this->enqueue_variation($product_id, 'update');
		} elseif ('product' === $post_type) {
			$this->enqueue_product_and_children($product_id, 'update');
		}
	}

	public function on_post_status_transition($new_status, $old_status, $post)
	{
		if (! $post || ! in_array($post->post_type, ['product', 'product_variation'], true)) {
			return;
		}

		if ($new_status === $old_status || 'auto-draft' === $new_status) {
			return;
		}

		$operation = ($new_status === 'trash') ? 'delete' : 'update';

		if ('product_variation' === $post->post_type) {
			$this->enqueue_variation($post->ID, $operation, $post->post_parent);
		} else {
			$this->enqueue_product_and_children($post->ID, $operation);
		}
	}

	public function on_post_trashed($post_id)
	{
		$post = get_post($post_id);
		if ($post && 'product' === $post->post_type) {
			$this->enqueue_product_and_children((int) $post_id, 'delete');
		} elseif ($post && 'product_variation' === $post->post_type) {
			$this->enqueue_variation($post_id, 'delete', $post->post_parent);
		}
	}

	/**
	 * Restore from trash. 'update' replaces the pending 'delete' (latest operation wins), so a
	 * product trashed and restored before the worker runs is sent as live, not deleted.
	 */
	public function on_post_untrashed($post_id)
	{
		$post = get_post($post_id);
		if ($post && 'product' === $post->post_type) {
			$this->enqueue_product_and_children((int) $post_id, 'update');
		} elseif ($post && 'product_variation' === $post->post_type) {
			$this->enqueue_variation($post_id, 'update', $post->post_parent);
		}
	}

	/**
	 * Permanent delete. Runs BEFORE the post (and, for variable products, its variations) is
	 * removed, which is the last moment we can still read the parent / children ids.
	 */
	public function on_post_deleted($post_id)
	{
		$post = get_post($post_id);
		if ($post && 'product' === $post->post_type) {
			$this->enqueue_product_and_children((int) $post_id, 'delete');
		} elseif ($post && 'product_variation' === $post->post_type) {
			$this->enqueue_variation($post_id, 'delete', $post->post_parent);
		}
	}

	public function on_terms_changed($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
	{
		$watched = 'product_cat' === $taxonomy
			|| in_array($taxonomy, Reg_Field_Mapper::BRAND_TAXONOMIES, true)
			|| 0 === strpos($taxonomy, 'pa_');
		if (! $watched) {
			return;
		}

		// Nothing actually changed (WooCommerce re-sets terms on every save).
		$old = array_map('intval', (array) $old_tt_ids);
		$new = array_map('intval', (array) $tt_ids);
		sort($old);
		sort($new);
		if ($old === $new) {
			return;
		}

		$post = get_post($object_id);
		if (! $post || 'product' !== $post->post_type) {
			return;
		}

		$this->enqueue_product_and_children((int) $object_id, 'update');

		// Category membership changed: Regolith resolves categories from the latest COMPLETE
		// membership snapshot, so it needs a fresh one or this product's rows may be rejected.
		if ('product_cat' === $taxonomy) {
			Reg_Onboarding::I()->mark_dirty('memberships');
		}
	}

	/**
	 * A term was renamed/edited. Brand or attribute value renames change the rows of every
	 * product using the term. A category edit (name/parent) needs a new category snapshot.
	 */
	public function on_term_edited($term_id, $tt_id, $taxonomy)
	{
		if ('product_cat' === $taxonomy) {
			Reg_Onboarding::I()->mark_dirty('categories');
			return;
		}

		if (in_array($taxonomy, Reg_Field_Mapper::BRAND_TAXONOMIES, true) || 0 === strpos($taxonomy, 'pa_')) {
			$object_ids = get_objects_in_term((int) $term_id, $taxonomy);
			foreach ((array) $object_ids as $object_id) {
				if ('product' === get_post_type($object_id)) {
					$this->enqueue_product_and_children((int) $object_id, 'update');
				}
			}
		}
	}

	public function on_term_created_or_deleted($term_id, $tt_id, $taxonomy)
	{
		if ('product_cat' === $taxonomy) {
			Reg_Onboarding::I()->mark_dirty('categories');
		}
	}
}
