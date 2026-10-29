<?php

/**
 * Regolith Inventory Sync Admin Dashboard
 *
 * Minimum-effort UI on purpose (a proper one comes later). One status panel, rendered in PHP
 * and swapped in over AJAX, plus a handful of buttons:
 * - Start a Full sync, abandon an active run
 * - Keep the engine working while the page is open (doesn't depend on cron in dev)
 * - Retry failed / rejected items, resume a paused (blocked) sync
 * - Nuke / Reset All: local sync data + setup progress, back to the wizard
 *
 * The panel HTML is built server-side with everything escaped, so the JS just drops it in.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Sync_Admin
{
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
		// AJAX Handlers
		add_action('wp_ajax_regolith_sync_tick', [$this, 'ajax_tick']);
		add_action('wp_ajax_regolith_start_full_sync', [$this, 'ajax_start_full_sync']);
		add_action('wp_ajax_regolith_abandon_run', [$this, 'ajax_abandon_run']);
		add_action('wp_ajax_regolith_retry_item', [$this, 'ajax_retry_item']);
		add_action('wp_ajax_regolith_retry_all_failed', [$this, 'ajax_retry_all_failed']);
		add_action('wp_ajax_regolith_unblock_sync', [$this, 'ajax_unblock']);
		add_action('wp_ajax_regolith_nuke_tables', [$this, 'ajax_nuke_tables']);
	}

	// MARK: Page

	/**
	 * The raw sync engine panel, shown in Settings → Advanced (support / developers).
	 * It keeps its own nonce and inline script from when it was a standalone dev page.
	 */
	public function render_panel()
	{
		if (! current_user_can('manage_options')) {
			wp_die(__('You do not have permission to access this page.', 'regolith-integration'));
		}
		$nonce = wp_create_nonce('regolith_sync_admin');
?>
		<div class="reg-legacy-panel">

			<p>
				<button type="button" class="button button-primary reg-action" data-action="regolith_start_full_sync">Start Full Catalog Sync</button>
				<button type="button" class="button reg-action" data-action="regolith_retry_all_failed" data-confirm="Re-queue every failed and rejected product?">Retry Failed &amp; Rejected</button>
				<label style="margin-left:15px;"><input type="checkbox" id="reg-keep-working"> Keep syncing while this page is open</label>
				<span class="spinner" id="reg-spinner" style="float:none;margin:0 0 0 8px;"></span>
				<span id="reg-msg" style="margin-left:8px;"></span>
			</p>

			<div id="reg-status"><?php echo $this->render_status_html(); // already escaped inside ?></div>

			<div class="card" style="max-width:100%;border-left:4px solid #d63638;">
				<h2>Nuke / Reset All</h2>
				<p>Starts this store over from the setup wizard: wipes the local sync tables (runs, batches, queue, product ledger) and the setup progress (go-live flag, product details mapping, product list snapshot). Your connection (API key, server address, store identity) is kept.</p>
				<p>Regolith keeps its own side: category decisions already saved there show up as done in the wizard, and a Full run left open there is abandoned automatically by the next Full sync. Product updates pause until you go live again.</p>
				<button type="button" class="button button-link-delete reg-action" data-action="regolith_nuke_tables" data-confirm="Nuke everything local and restart the setup wizard? This cannot be undone.">Nuke / Reset All</button>
			</div>
		</div>

		<script>
			jQuery(function($) {
				var nonce = '<?php echo esc_js($nonce); ?>';
				var busy = false;

				function msg(text, ok) {
					$('#reg-msg').text(text || '').css('color', ok === false ? '#d63638' : '#2271b1');
				}

				// One poll: optionally work the engine for a few seconds, always refresh the panel.
				function tick() {
					if (busy) return;
					busy = true;
					var work = $('#reg-keep-working').is(':checked') ? 1 : 0;
					$('#reg-spinner').toggleClass('is-active', !!work);
					$.post(ajaxurl, {action: 'regolith_sync_tick', _ajax_nonce: nonce, work: work}, function(res) {
						if (res && res.success) {
							$('#reg-status').html(res.data.html);
						}
					}).always(function(res) {
						busy = false;
						$('#reg-spinner').removeClass('is-active');
						var active = res && res.success && res.data.active;
						setTimeout(tick, active && work ? 1000 : 10000);
					});
				}

				$(document).on('click', '.reg-action', function(e) {
					e.preventDefault();
					var $b = $(this);
					if ($b.data('confirm') && !confirm($b.data('confirm'))) return;
					$b.prop('disabled', true);
					$.post(ajaxurl, $.extend({action: $b.data('action'), _ajax_nonce: nonce}, $b.data('args') || {}), function(res) {
						msg(res.data && (res.data.message || res.data), res.success);
						if (res.success && res.data && res.data.redirect) {
							window.location.href = res.data.redirect;
							return;
						}
						if (res.success && res.data && res.data.html) {
							$('#reg-status').html(res.data.html);
						}
					}).fail(function() {
						msg('Request failed.', false);
					}).always(function() {
						$b.prop('disabled', false);
					});
				});

				setTimeout(tick, 500);
			});
		</script>
<?php
	}

	// MARK: Status Panel

	/**
	 * The whole status panel as HTML. Every dynamic value goes through esc_html/esc_attr.
	 */
	public function render_status_html()
	{
		$status = Reg_Sync_Service::I()->get_status();
		$db     = Reg_Sync_DB::I();

		ob_start();

		if (! $status['store_id']) : ?>
			<div class="notice notice-error inline"><p>Store registration is required before syncing inventory. Use <em>Test Connection</em> in <a href="<?php echo esc_url(Reg_UI::url('settings', ['tab' => 'connection'])); ?>">Settings</a>.</p></div>
		<?php endif;

		if ($status['blocked']) : ?>
			<div class="notice notice-error inline">
				<p><strong>Sync is paused.</strong> Regolith answered <code><?php echo esc_html($status['blocked']['code']); ?></code>: <?php echo esc_html($status['blocked']['message']); ?>
					(<?php echo esc_html($this->local_time($status['blocked']['at'])); ?>)</p>
				<p>Nothing is sent until this is fixed. Typical causes: wrong/revoked API key, store unlinked by a Regolith admin (re-register with <em>Test Connection</em>, then redo onboarding), vendor without an active country. Queued work is kept.
					<button type="button" class="button reg-action" data-action="regolith_unblock_sync">Resume syncing</button></p>
			</div>
		<?php endif; ?>

		<h2>Now</h2>
		<?php if ($status['active']) : ?>
			<table class="widefat striped" style="max-width:1000px;">
				<thead><tr><th>Mode</th><th>Sync ID</th><th>Status</th><th>Batches</th><th>Items</th><th>Next</th><th>Last error</th><th></th></tr></thead>
				<tbody>
					<?php foreach ($status['active'] as $run) : ?>
						<tr>
							<td><strong><?php echo esc_html($run['mode']); ?></strong></td>
							<td><code><?php echo esc_html($run['sync_id']); ?></code></td>
							<td><?php echo esc_html($run['status']); ?></td>
							<td><?php echo esc_html($run['completed_batches'] . ' / ' . $run['total_batches']); ?></td>
							<td><?php echo esc_html($run['total_items']); ?></td>
							<td><?php echo esc_html($run['next_batch']); ?></td>
							<td><small><?php echo esc_html($run['last_error']); ?></small></td>
							<td><button type="button" class="button button-small reg-action" data-action="regolith_abandon_run" data-args="<?php echo esc_attr(wp_json_encode(['sync_id' => $run['sync_id']])); ?>" data-confirm="Abandon this run? An abandoned Full run never deactivates missing products.">Abandon</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p>No sync running.</p>
		<?php endif; ?>

		<p>
			<strong>Delta queue:</strong>
			<?php echo esc_html(sprintf(
				'%d pending (%d waiting on a retry timer), %d in flight, %d failed',
				$status['queue']['pending'],
				$status['queue']['waiting'],
				$status['queue']['in_flight'],
				$status['queue']['failed']
			)); ?>
			&nbsp;|&nbsp;
			<strong>Ledger:</strong>
			<?php echo esc_html(sprintf(
				'%d synced, %d inactive, %d skipped, %d stale, %d rejected (of %d)',
				$status['totals']['synced'],
				$status['totals']['deactivated'],
				$status['totals']['skipped'],
				$status['totals']['stale'],
				$status['totals']['rejected'],
				$status['totals']['total']
			)); ?>
		</p>
		<p>
			<strong>Last closed Full:</strong> <?php echo $status['last_full'] ? esc_html($status['last_full']['status'] . ', ' . $this->local_time($status['last_full']['completed_at'])) : 'never'; ?>
			&nbsp;|&nbsp;
			<strong>Last Delta:</strong> <?php echo $status['last_delta'] ? esc_html($status['last_delta']['status'] . ', ' . $this->local_time($status['last_delta']['completed_at'])) : 'never'; ?>
			&nbsp;|&nbsp;
			<strong>Next background tick:</strong> <?php echo esc_html($this->next_tick()); ?>
		</p>

		<?php
		$failed_queue = $db->get_failed_queue_items(20);
		if ($failed_queue) : ?>
			<h2>Failed Queue Items</h2>
			<p>Their batch failed as a whole (not a per-product problem). They stay here until retried or the product is edited again.</p>
			<table class="widefat striped" style="max-width:1000px;">
				<thead><tr><th>Product</th><th>Variation</th><th>Operation</th><th>Error</th></tr></thead>
				<tbody>
					<?php foreach ($failed_queue as $q) : ?>
						<tr>
							<td><?php echo esc_html($this->product_label((int) $q['product_id'], 0)); ?></td>
							<td><?php echo esc_html($q['variation_id'] ?: '—'); ?></td>
							<td><?php echo esc_html($q['operation']); ?></td>
							<td><code><?php echo esc_html($q['last_error_code']); ?></code> <?php echo esc_html($q['last_error_message']); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2>Rejected / Action Required (<?php echo esc_html($status['rejected']); ?>)</h2>
		<?php
		$rejected = $db->get_rejected_products(50, 0);
		if ($rejected) : ?>
			<table class="widefat striped" style="max-width:1200px;">
				<thead><tr><th style="width:25%;">Product / Variation</th><th>Status</th><th>Error</th><th>What to do</th><th></th></tr></thead>
				<tbody>
					<?php foreach ($rejected as $item) :
						$prod_id = (int) $item['product_id'];
						$var_id  = (int) $item['variation_id'];
						$action  = $this->get_actionable_resolution((string) $item['last_error_code'], $prod_id);
						?>
						<tr>
							<td><?php echo esc_html($this->product_label($prod_id, $var_id)); ?><br><small>#<?php echo esc_html($prod_id . ($var_id ? " / var #{$var_id}" : '')); ?></small></td>
							<td><?php echo esc_html($item['last_sync_status']); ?><?php echo $item['last_error_retryable'] ? '<br><small>retryable, attempt ' . esc_html($item['retry_count']) . '</small>' : ''; ?></td>
							<td><code><?php echo esc_html($item['last_error_code']); ?></code><br><small><?php echo esc_html($item['last_error_message']); ?></small></td>
							<td><?php if ($action) : ?><a href="<?php echo esc_url($action['url']); ?>" target="_blank"><?php echo esc_html($action['label']); ?></a><?php endif; ?></td>
							<td><button type="button" class="button button-small reg-action" data-action="regolith_retry_item" data-args="<?php echo esc_attr(wp_json_encode(['product_id' => $prod_id, 'variation_id' => $var_id])); ?>">Retry</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p>&check; Nothing rejected.</p>
		<?php endif; ?>

		<h2>Recent Runs</h2>
		<?php $recent = $db->get_recent_sync_runs(10);
		if ($recent) : ?>
			<table class="widefat striped" style="max-width:1200px;">
				<thead><tr><th>Mode</th><th>Sync ID</th><th>Status</th><th>Items</th><th>Batches</th><th>Created / Updated / Unchanged / Inactive / Skipped / Stale / Rejected</th><th>Started</th><th>Finished</th><th>Note</th></tr></thead>
				<tbody>
					<?php foreach ($recent as $run) : ?>
						<tr>
							<td><?php echo esc_html($run['mode']); ?></td>
							<td><code title="<?php echo esc_attr($run['sync_id']); ?>"><?php echo esc_html(substr($run['sync_id'], 0, 8)); ?>…</code></td>
							<td><strong><?php echo esc_html($run['status']); ?></strong><?php echo ($run['server_status'] && $run['server_status'] !== $run['status']) ? '<br><small>server: ' . esc_html($run['server_status']) . '</small>' : ''; ?></td>
							<td><?php echo esc_html($run['total_items']); ?></td>
							<td><?php echo esc_html($run['completed_batches'] . ' / ' . $run['total_batches']); ?></td>
							<td><?php echo esc_html(implode(' / ', [$run['created_count'], $run['updated_count'], $run['unchanged_count'], $run['deactivated_count'], $run['skipped_count'] ?? 0, $run['stale_count'] ?? 0, $run['rejected_count']])); ?></td>
							<td><small><?php echo esc_html($this->local_time($run['started_at'])); ?></small></td>
							<td><small><?php echo esc_html($run['completed_at'] ? $this->local_time($run['completed_at']) : '—'); ?></small></td>
							<td><small><?php echo esc_html($run['last_error_code'] ? $run['last_error_code'] . ': ' . $run['last_error_message'] : ''); ?></small></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p>No sync runs recorded yet.</p>
		<?php endif;

		return ob_get_clean();
	}

	/**
	 * DB times are UTC, show them in the site's timezone.
	 */
	private function local_time($gmt)
	{
		return $gmt ? get_date_from_gmt($gmt, 'Y-m-d H:i:s') : '';
	}

	private function product_label($product_id, $variation_id)
	{
		$product = wc_get_product($variation_id ?: $product_id);
		return $product ? wp_strip_all_tags($product->get_name()) : 'Deleted product #' . ($variation_id ?: $product_id);
	}

	private function next_tick()
	{
		if (! function_exists('as_next_scheduled_action')) {
			return 'Action Scheduler not available';
		}
		$next = as_next_scheduled_action(Reg_Sync_Queue::TICK_HOOK, [], Reg_Sync_Queue::GROUP);
		if (true === $next) {
			return 'running now';
		}
		return $next ? human_time_diff(time(), $next) . (time() > $next ? ' overdue (is WP-Cron running?)' : '') : 'not scheduled (open any admin page to schedule it)';
	}

	/**
	 * Provide actionable resolution link for error codes as per Section 48.
	 */
	public function get_actionable_resolution($error_code, $product_id)
	{
		switch ($error_code) {
			case 'CATEGORY_SELECTION_REQUIRED':
			case 'CATEGORY_DECISION_REQUIRED':
			case 'VENDOR_CATEGORY_NOT_IN_STORE':
			case 'INTERNAL_CATEGORY_INACTIVE':
				return [
					'label' => 'Open Category Mapper (map the category / pick one)',
					'url'   => Reg_UI::url('settings', ['tab' => 'categories']),
				];
			case 'IMAGE_IMPORT_FAILED':
			case 'IMAGE_UNSAFE':
			case 'IMAGE_LIMIT_EXCEEDED':
				return [
					'label' => 'Check the product image (public HTTPS, JPEG/PNG/WebP, ≤10 MB, ≤40 MP)',
					'url'   => get_edit_post_link($product_id, 'raw'),
				];
			case 'REQUIRED_ITEM_FIELD_MISSING':
			case 'VARIATION_PARENT_MISMATCH':
				return [
					'label' => 'Fix the product data (name, price, SKU length)',
					'url'   => get_edit_post_link($product_id, 'raw'),
				];
			case 'CURRENCY_CONVERSION_FAILED':
			case 'VENDOR_COUNTRY_REQUIRED':
				return [
					'label' => 'Currency problem on Regolith\'s side, ask Regolith support',
					'url'   => Reg_UI::url('settings', ['tab' => 'connection']),
				];
			case 'SERIALIZE_FAILED':
				return [
					'label' => 'Plugin could not read this product, check the PHP error log',
					'url'   => get_edit_post_link($product_id, 'raw'),
				];
			default:
				return [
					'label' => 'Edit Product Data',
					'url'   => get_edit_post_link($product_id, 'raw'),
				];
		}
	}

	// MARK: AJAX Callbacks

	private function guard()
	{
		check_ajax_referer('regolith_sync_admin');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(__('Permission denied.', 'regolith-integration'), 403);
		}
	}

	/**
	 * Poll from the open page. With work=1 it also runs the engine for a bit, which is what
	 * makes a Full sync move along in dev without waiting on cron.
	 */
	public function ajax_tick()
	{
		$this->guard();

		$result = null;
		if (! empty($_POST['work'])) {
			$result = Reg_Sync_Service::I()->work(10);
		}

		wp_send_json_success([
			'html'   => $this->render_status_html(),
			'active' => (bool) Reg_Sync_DB::I()->get_active_runs() || Reg_Sync_DB::I()->count_due_queue_items() > 0,
			'state'  => $result['state'] ?? null,
		]);
	}

	public function ajax_start_full_sync()
	{
		$this->guard();

		$result = Reg_Sync_Service::I()->start_full_sync();

		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		wp_send_json_success([
			'message' => 'Full sync ' . $result['sync_id'] . ' created. Building the catalog snapshot…',
			'html'    => $this->render_status_html(),
		]);
	}

	public function ajax_abandon_run()
	{
		$this->guard();

		$sync_id = isset($_POST['sync_id']) ? sanitize_text_field(wp_unslash($_POST['sync_id'])) : '';
		if (empty($sync_id)) {
			wp_send_json_error(__('Invalid sync ID.', 'regolith-integration'));
		}

		$result = Reg_Sync_Service::I()->abandon_run($sync_id);
		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		wp_send_json_success(['message' => $result['message'], 'html' => $this->render_status_html()]);
	}

	public function ajax_retry_item()
	{
		$this->guard();

		$prod_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
		$var_id  = isset($_POST['variation_id']) ? (int) $_POST['variation_id'] : 0;

		if (! $prod_id) {
			wp_send_json_error(__('Invalid product ID.', 'regolith-integration'));
		}

		Reg_Sync_DB::I()->enqueue_item($prod_id, $var_id, 'update');
		Reg_Sync_Queue::I()->schedule_work(0);

		wp_send_json_success(['message' => __('Item enqueued for sync.', 'regolith-integration'), 'html' => $this->render_status_html()]);
	}

	public function ajax_retry_all_failed()
	{
		$this->guard();

		$count = (int) Reg_Sync_DB::I()->retry_failed_queue_items();

		foreach (Reg_Sync_DB::I()->get_rejected_products(1000, 0) as $item) {
			Reg_Sync_DB::I()->enqueue_item((int) $item['product_id'], (int) $item['variation_id'], 'update');
			$count++;
		}
		Reg_Sync_Queue::I()->schedule_work(0);

		wp_send_json_success([
			'message' => sprintf(__('%d items re-enqueued for sync.', 'regolith-integration'), $count),
			'html'    => $this->render_status_html(),
		]);
	}

	public function ajax_unblock()
	{
		$this->guard();
		Reg_Sync_Service::I()->unblock();
		wp_send_json_success(['message' => __('Resumed. If the cause isn\'t fixed it will pause again on the next request.', 'regolith-integration'), 'html' => $this->render_status_html()]);
	}

	/**
	 * Nuke / Reset All: local sync data AND setup progress, then back to the wizard.
	 * The connection stays (see Reg_Wizard::reset_setup()).
	 */
	public function ajax_nuke_tables()
	{
		$this->guard();

		Reg_Sync_DB::I()->nuke_all_tables();
		Reg_Wizard::I()->reset_setup();

		wp_send_json_success([
			'message'  => __('Everything local was reset. Opening the setup wizard…', 'regolith-integration'),
			'redirect' => Reg_UI::url('setup', ['step' => 'welcome']),
		]);
	}
}
