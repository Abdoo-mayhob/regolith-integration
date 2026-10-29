<?php

/**
 * Regolith Dashboard
 *
 * What a store owner sees after setup:
 * - Overview: one health card (All good / Syncing / Needs attention / Paused), four numbers,
 *   recent activity in plain words, and the one button they might need ("Send everything again").
 * - Needs attention: problems grouped by REASON (not by product, not by error code), each with
 *   what to do and a "Try again". Plus a quiet "Not listed" section for products that are
 *   simply out of stock, unpublished and so on.
 * - Products list: a status label per product (Live / Not listed / Needs attention / Syncing),
 *   and a "Sync to Regolith" row action.
 * - Product edit screen: a small Regolith box with the same status.
 *
 * Everything here reads the local ledger (Reg_Sync_DB), so no page waits on the Regolith API.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Dashboard
{
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
		add_action('wp_ajax_regolith_overview_refresh', [$this, 'ajax_overview_refresh']);
		add_action('wp_ajax_regolith_resync_all', [$this, 'ajax_resync_all']);
		add_action('wp_ajax_regolith_retry_group', [$this, 'ajax_retry_group']);
		add_action('wp_ajax_regolith_product_sync', [$this, 'ajax_product_sync']);
		add_action('wp_ajax_regolith_resume_sync', [$this, 'ajax_resume_sync']);

		add_filter('manage_product_posts_columns', [$this, 'add_product_column'], 20);
		add_action('manage_product_posts_custom_column', [$this, 'render_product_column'], 10, 2);
		add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
		add_action('add_meta_boxes_product', [$this, 'add_metabox']);
	}

	// MARK: Product Status

	/**
	 * Store-owner status of a product, from the ledger + queue + the product itself.
	 *
	 * @return array{tone: string, label: string, reason: string}
	 */
	public function product_status($product_id)
	{
		global $wpdb;

		$product_id = (int) $product_id;
		$product    = wc_get_product($product_id);

		$queued = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}regolith_sync_queue WHERE product_id = %d AND status IN ('pending', 'in_flight')",
			$product_id
		));

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}regolith_product_sync_state WHERE product_id = %d",
			$product_id
		), ARRAY_A);

		// Variable products: only the variation rows matter (the parent isn't listed itself).
		// Deleted variations are gone from the store, so they don't count either.
		if ($product && $product->is_type('variable')) {
			$rows = array_filter($rows, static fn ($r) => (int) $r['variation_id'] > 0 && get_post((int) $r['variation_id']));
		}

		$errors = array_filter($rows, static fn ($r) => ! empty($r['last_error_code']));
		// is_active, not the status: "Unchanged" can be unchanged-and-inactive. NULL = a row from
		// before is_active existed, fall back to the status.
		$live   = array_filter($rows, static fn ($r) => empty($r['last_error_code']) && (null !== $r['is_active']
			? 1 === (int) $r['is_active']
			: in_array($r['last_sync_status'], ['Created', 'Updated', 'Unchanged'], true)));

		if ($errors) {
			$first = reset($errors);
			$msg   = Reg_Messages::get($first['last_error_code'], (string) $first['last_error_message']);
			return ['tone' => 'warn' === $msg['level'] ? 'warn' : 'error', 'label' => 'Needs attention', 'reason' => $msg['title']];
		}

		if ($queued) {
			return ['tone' => 'info', 'label' => 'Syncing', 'reason' => 'Changes are on their way to Regolith.'];
		}

		if (! $rows) {
			if ($product && $product->is_type('grouped')) {
				return ['tone' => 'muted', 'label' => 'Group', 'reason' => 'Grouped products aren\'t listed, their products are.'];
			}
			return ['tone' => 'muted', 'label' => 'Not synced yet', 'reason' => 'This product hasn\'t been sent to Regolith yet.'];
		}

		if ($live) {
			$label = count($rows) > 1 ? sprintf('Live (%d of %d)', count($live), count($rows)) : 'Live';
			return ['tone' => 'ok', 'label' => $label, 'reason' => 'Listed on Regolith.'];
		}

		return ['tone' => 'muted', 'label' => 'Not listed', 'reason' => $product ? $this->unlisted_reason($product) : 'Removed from your store.'];
	}

	/**
	 * Why a product that reached Regolith isn't listed, worked out locally (Regolith only says
	 * "inactive"). Order matters: the first reason that applies is the one people can fix.
	 */
	public function unlisted_reason(WC_Product $product)
	{
		$check = $product->is_type('variable') ? (array_filter(array_map('wc_get_product', $product->get_children())) ?: [$product]) : [$product];

		$hidden = array_map('intval', (array) get_option('regolith_hidden_categories', []));
		$cats   = array_map('intval', wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'ids']) ?: []);
		if ($cats && $hidden && ! array_diff($cats, $hidden)) {
			return 'Its categories are set to "Don\'t list on Regolith".';
		}
		if ('publish' !== $product->get_status()) {
			return 'Not published.';
		}

		$no_stock = $no_price = $not_purchasable = 0;
		foreach ($check as $unit) {
			if (! $unit->is_in_stock()) {
				$no_stock++;
			} elseif ('' === (string) $unit->get_price() || (float) $unit->get_price() <= 0) {
				$no_price++;
			} elseif (! $unit->is_purchasable()) {
				$not_purchasable++;
			}
		}
		if ($no_stock) {
			return 'Out of stock.';
		}
		if ($no_price) {
			return 'No price.';
		}
		if ($not_purchasable) {
			return $product->is_type('external') ? 'External / affiliate products can\'t be bought through Regolith.' : 'Not purchasable.';
		}
		return 'Regolith marked it inactive (image or category). It\'s re-checked on every update.';
	}

	// MARK: Overview

	public function render_overview()
	{
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_die(__('You do not have permission to access this page.', 'regolith-integration'));
		}
		?>
		<div class="reg-page">
			<?php Reg_UI::header('overview'); ?>
			<div class="reg-page__body" data-reg-overview>
				<?php echo $this->overview_html(); // escaped inside ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The live part of the overview, re-rendered by the refresh poll.
	 */
	public function overview_html()
	{
		$db      = Reg_Sync_DB::I();
		$service = Reg_Sync_Service::I();
		$status  = $service->get_status();
		$totals  = $status['totals'];
		$queue   = $status['queue'];
		$blocked = $status['blocked'];
		$full    = $db->get_active_full_sync_run();
		$last    = $db->get_latest_sync_run(null, ['Completed', 'CompletedWithErrors']);

		// Health: the first that applies.
		if ($blocked) {
			$msg    = Reg_Messages::get($blocked['code'], $blocked['message']);
			$health = ['tone' => 'error', 'title' => 'Sync is paused', 'text' => $msg['title'] . '. ' . $msg['help'], 'link' => Reg_Messages::action_link($msg), 'resume' => true];
		} elseif ($full) {
			$pct    = 'InProgress' === $full['status'] && $full['total_batches'] ? (int) round(100 * $full['completed_batches'] / $full['total_batches']) : 0;
			$health = ['tone' => 'info', 'title' => 'Sending your whole catalog', 'text' => sprintf('%d%% done. Regolith imports every product and its image, this can take a while.', $pct), 'progress' => $pct];
		} elseif ($status['rejected']) {
			$health = ['tone' => 'warn', 'title' => sprintf('%d product%s need%s your attention', $status['rejected'], 1 === $status['rejected'] ? '' : 's', 1 === $status['rejected'] ? 's' : ''), 'text' => 'Everything else is in sync. Have a look when you can.', 'link' => ['url' => Reg_UI::url('attention'), 'label' => 'See what needs attention']];
		} else {
			$health = ['tone' => 'ok', 'title' => 'Everything is in sync', 'text' => 'Changes in your store reach Regolith automatically within a few minutes.'];
		}

		ob_start();
		?>
		<section class="reg-health reg-health--<?php echo esc_attr($health['tone']); ?>">
			<div class="reg-health__icon" aria-hidden="true"></div>
			<div class="reg-health__body">
				<h2><?php echo esc_html($health['title']); ?></h2>
				<p><?php echo esc_html($health['text']); ?></p>
				<?php if (isset($health['progress'])) : ?><progress max="100" value="<?php echo (int) $health['progress']; ?>"></progress><?php endif; ?>
				<p class="reg-health__meta">Last update sent <?php echo esc_html($last ? Reg_UI::ago($last['completed_at']) : 'never'); ?>.</p>
			</div>
			<div class="reg-health__actions">
				<?php if (! empty($health['resume'])) : ?>
					<button type="button" class="reg-btn reg-btn--primary" data-reg-action="regolith_resume_sync">I fixed it, resume</button>
				<?php endif; ?>
				<?php if (! empty($health['link'])) : ?>
					<a class="reg-btn reg-btn--ghost" href="<?php echo esc_url($health['link']['url']); ?>"><?php echo esc_html($health['link']['label']); ?></a>
				<?php endif; ?>
			</div>
		</section>

		<?php if (get_option('regolith_fields_changed_at') && ! $full) : ?>
			<div class="reg-alert reg-alert--info reg-alert--action">
				<span><strong>You changed your product details settings.</strong> Products pick it up the next time they change. Send everything now to update them all at once.</span>
				<button type="button" class="reg-btn reg-btn--primary reg-btn--small" data-reg-action="regolith_resync_all" data-confirm="Send your whole catalog to Regolith again?">Send everything now</button>
			</div>
		<?php endif; ?>

		<?php if ('AttentionRequired' === get_option('regolith_onboarding_status')) : ?>
			<div class="reg-alert reg-alert--warn reg-alert--action">
				<span><strong>A new category needs a decision.</strong> Products in it stay unlisted until you choose where they belong.</span>
				<a class="reg-btn reg-btn--ghost reg-btn--small" href="<?php echo esc_url(Reg_UI::url('settings', ['tab' => 'categories'])); ?>">Review categories</a>
			</div>
		<?php endif; ?>

		<div class="reg-tiles">
			<a class="reg-tile reg-tile--ok" href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">
				<span class="reg-tile__num"><?php echo (int) $totals['synced']; ?></span><span class="reg-tile__label">Live on Regolith</span>
			</a>
			<a class="reg-tile" href="<?php echo esc_url(Reg_UI::url('attention') . '#not-listed'); ?>">
				<span class="reg-tile__num"><?php echo (int) ($totals['deactivated'] + $totals['skipped']); ?></span><span class="reg-tile__label">Not listed</span>
			</a>
			<a class="reg-tile <?php echo $status['rejected'] ? 'reg-tile--warn' : ''; ?>" href="<?php echo esc_url(Reg_UI::url('attention')); ?>">
				<span class="reg-tile__num"><?php echo (int) $status['rejected']; ?></span><span class="reg-tile__label">Need attention</span>
			</a>
			<div class="reg-tile">
				<span class="reg-tile__num"><?php echo (int) ($queue['pending'] + $queue['in_flight']); ?></span><span class="reg-tile__label">Waiting to be sent</span>
			</div>
		</div>

		<section class="reg-card">
			<div class="reg-card__head">
				<h3>Recent activity</h3>
				<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-action="regolith_resync_all" data-confirm="Send your whole catalog to Regolith again? Only needed after big changes, everyday edits are sent automatically." <?php disabled((bool) $full); ?>>Send everything again</button>
			</div>
			<?php $runs = $db->get_recent_sync_runs(8);
			if ($runs) : ?>
				<ul class="reg-activity">
					<?php foreach ($runs as $run) : ?>
						<li><?php echo $this->describe_run($run); // escaped inside ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="reg-muted">Nothing sent yet.</p>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * One activity line: "Updated 4 products · 5 minutes ago", in words a store owner uses.
	 */
	private function describe_run(array $run)
	{
		$changed = (int) $run['created_count'] + (int) $run['updated_count'];
		$issues  = (int) $run['rejected_count'];
		$items   = (int) $run['total_items'];
		$when    = Reg_UI::ago($run['completed_at'] ?: $run['started_at']);

		if ('Full' === $run['mode']) {
			$what = sprintf('Whole catalog sent (%d items)', $items);
		} else {
			$what = sprintf('%d product update%s sent', $items, 1 === $items ? '' : 's');
		}

		switch ($run['status']) {
			case 'Building':
			case 'InProgress':
				$tone = 'info';
				$label = 'In progress';
				break;
			case 'Completed':
				$tone = 'ok';
				$label = $changed ? sprintf('%d changed', $changed) : 'No changes';
				break;
			case 'CompletedWithErrors':
				$tone = 'warn';
				$label = sprintf('%d issue%s', $issues, 1 === $issues ? '' : 's');
				break;
			default:
				$tone = 'error';
				$label = 'Abandoned' === $run['status'] ? 'Stopped' : 'Didn\'t finish';
		}

		return sprintf('<span>%s</span> %s <span class="reg-muted">%s</span>', esc_html($what), Reg_UI::pill($label, $tone), esc_html($when));
	}

	// MARK: Needs Attention

	public function render_attention()
	{
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_die(__('You do not have permission to access this page.', 'regolith-integration'));
		}

		$db       = Reg_Sync_DB::I();
		$rejected = $db->get_rejected_products(1000, 0);
		$groups   = [];
		foreach ($rejected as $row) {
			$groups[(string) $row['last_error_code']][] = $row;
		}
		// Most common problem first.
		uasort($groups, static fn ($a, $b) => count($b) <=> count($a));

		global $wpdb;
		$unlisted = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}regolith_product_sync_state
			WHERE (is_active = 0 OR last_sync_status IN ('Deactivated', 'Skipped')) AND (last_error_code IS NULL OR last_error_code = '')
			ORDER BY updated_at DESC LIMIT 500",
			ARRAY_A
		);
		?>
		<div class="reg-page">
			<?php Reg_UI::header('attention'); ?>
			<div class="reg-page__body">
				<h1 class="reg-title">Needs attention</h1>

				<?php if (! $groups) : ?>
					<div class="reg-empty">
						<div class="reg-big-check" aria-hidden="true"></div>
						<h2>Nothing needs your attention</h2>
						<p class="reg-muted">Every product Regolith received is fine.</p>
					</div>
				<?php endif; ?>

				<?php foreach ($groups as $code => $rows) :
					$msg  = Reg_Messages::get($code, (string) $rows[0]['last_error_message']);
					$link = Reg_Messages::action_link($msg);
					?>
					<section class="reg-card reg-issue reg-issue--<?php echo esc_attr($msg['level']); ?>">
						<div class="reg-card__head">
							<div>
								<h3><?php echo esc_html($msg['title']); ?> <span class="reg-count"><?php echo count($rows); ?></span></h3>
								<p><?php echo esc_html($msg['help']); ?></p>
							</div>
							<div class="reg-issue__actions">
								<?php if ($link && 'product' !== $msg['action']) : ?>
									<a class="reg-btn reg-btn--ghost reg-btn--small" href="<?php echo esc_url($link['url']); ?>" <?php echo 'support' === $msg['action'] ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($link['label']); ?></a>
								<?php endif; ?>
								<button type="button" class="reg-btn reg-btn--primary reg-btn--small" data-reg-action="regolith_retry_group" data-args="<?php echo esc_attr(wp_json_encode(['code' => $code])); ?>">Try again</button>
							</div>
						</div>
						<ul class="reg-product-list">
							<?php foreach (array_slice($rows, 0, 50) as $row) : ?>
								<li><?php echo $this->product_line((int) $row['product_id'], (int) $row['variation_id'], 'product' === $msg['action']); // escaped inside ?></li>
							<?php endforeach; ?>
							<?php if (count($rows) > 50) : ?><li class="reg-muted">…and <?php echo count($rows) - 50; ?> more</li><?php endif; ?>
						</ul>
						<details class="reg-support-details">
							<summary>Details for support</summary>
							<code><?php echo esc_html($code); ?></code> <?php echo esc_html((string) $rows[0]['last_error_message']); ?>
						</details>
					</section>
				<?php endforeach; ?>

				<?php if ($unlisted) :
					$by_reason = [];
					foreach ($unlisted as $row) {
						$product = wc_get_product((int) $row['product_id']);
						$reason  = $product ? $this->unlisted_reason($product) : 'Removed from your store.';
						$by_reason[$reason][(int) $row['product_id']] = $row; // one line per product, not per variation
					}
					?>
					<details class="reg-card" id="not-listed">
						<summary class="reg-card__summary">
							<h3>Not listed, and that's expected <span class="reg-count reg-count--muted"><?php echo array_sum(array_map('count', $by_reason)); ?></span></h3>
							<span class="reg-muted">These go live on their own as soon as they qualify.</span>
						</summary>
						<?php foreach ($by_reason as $reason => $rows) : ?>
							<h4><?php echo esc_html($reason); ?></h4>
							<ul class="reg-product-list">
								<?php foreach (array_slice($rows, 0, 30) as $row) : ?>
									<li><?php echo $this->product_line((int) $row['product_id'], 0, true); // escaped inside ?></li>
								<?php endforeach; ?>
								<?php if (count($rows) > 30) : ?><li class="reg-muted">…and <?php echo count($rows) - 30; ?> more</li><?php endif; ?>
							</ul>
						<?php endforeach; ?>
					</details>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function product_line($product_id, $variation_id, $edit_link)
	{
		$product = wc_get_product($variation_id ?: $product_id);
		$name    = $product ? wp_strip_all_tags($product->get_name()) : 'Deleted product #' . ($variation_id ?: $product_id);
		$thumb   = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';

		$html  = $thumb ? '<img src="' . esc_url($thumb) . '" alt="">' : '<span class="reg-thumb-placeholder"></span>';
		$html .= '<span>' . esc_html($name) . '</span>';
		if ($edit_link && $product && get_post($product_id)) {
			$html .= ' <a href="' . esc_url(get_edit_post_link($product_id)) . '">Edit</a>';
		}
		return $html;
	}

	// MARK: Products List

	public function add_product_column($columns)
	{
		if (! Reg_Wizard::I()->is_complete()) {
			return $columns;
		}
		$out = [];
		foreach ($columns as $key => $label) {
			$out[$key] = $label;
			if ('price' === $key) {
				$out['regolith'] = 'Regolith';
			}
		}
		if (! isset($out['regolith'])) {
			$out['regolith'] = 'Regolith';
		}
		return $out;
	}

	public function render_product_column($column, $post_id)
	{
		if ('regolith' !== $column) {
			return;
		}
		$status = $this->product_status($post_id);
		echo '<span id="reg-status-' . (int) $post_id . '">' . Reg_UI::pill($status['label'], $status['tone'], $status['reason']) . '</span>';
	}

	public function row_actions($actions, $post)
	{
		if ('product' === $post->post_type && Reg_Wizard::I()->is_complete() && current_user_can('edit_product', $post->ID)) {
			$actions['regolith_sync'] = '<a href="#" data-reg-product-sync="' . (int) $post->ID . '">Sync to Regolith</a>';
		}
		return $actions;
	}

	// MARK: Product Metabox

	public function add_metabox()
	{
		if (! Reg_Wizard::I()->is_complete()) {
			return;
		}
		add_meta_box('regolith-status', 'Regolith', [$this, 'render_metabox'], 'product', 'side', 'default');
	}

	public function render_metabox($post)
	{
		$status = $this->product_status($post->ID);
		$last   = get_post_meta($post->ID, '_regolith_last_sync', true);
		?>
		<div class="reg-metabox">
			<p><span id="reg-status-<?php echo (int) $post->ID; ?>"><?php echo Reg_UI::pill($status['label'], $status['tone']); ?></span></p>
			<p><?php echo esc_html($status['reason']); ?></p>
			<p class="reg-muted">Last confirmed by Regolith: <?php echo $last ? esc_html(human_time_diff((int) $last, time()) . ' ago') : 'never'; ?></p>
			<p class="reg-muted">Saving the product sends the changes automatically.</p>
			<?php if ('Needs attention' === $status['label']) : ?>
				<p><a href="<?php echo esc_url(Reg_UI::url('attention')); ?>">What to do</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// MARK: AJAX

	private function guard($cap = null)
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can($cap ?: Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}
	}

	/**
	 * Overview poll: nudge the engine a little, return fresh HTML.
	 */
	public function ajax_overview_refresh()
	{
		$this->guard();
		Reg_Sync_Service::I()->work(5);
		wp_send_json_success(['html' => $this->overview_html()]);
	}

	public function ajax_resync_all()
	{
		$this->guard();
		$run = Reg_Sync_Service::I()->start_full_sync();
		if (is_wp_error($run)) {
			wp_send_json_error('FULL_SYNC_ALREADY_RUNNING' === $run->get_error_code() ? 'Your catalog is already being sent.' : $run->get_error_message());
		}
		delete_option('regolith_fields_changed_at');
		wp_send_json_success(['message' => 'Sending your whole catalog. This page updates as it goes.', 'html' => $this->overview_html()]);
	}

	public function ajax_retry_group()
	{
		$this->guard();
		$code  = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
		$count = 0;
		foreach (Reg_Sync_DB::I()->get_rejected_products(1000, 0) as $row) {
			if ((string) $row['last_error_code'] === $code) {
				Reg_Sync_DB::I()->enqueue_item((int) $row['product_id'], (int) $row['variation_id'], 'update');
				$count++;
			}
		}
		Reg_Sync_Queue::I()->schedule_work(0);
		wp_send_json_success(['message' => sprintf('%d product%s queued, they\'ll be re-checked in a minute or two.', $count, 1 === $count ? '' : 's')]);
	}

	public function ajax_resume_sync()
	{
		$this->guard();
		$test = Reg_API::I()->test_connection();
		if (is_wp_error($test)) {
			$msg = Reg_Messages::from_error($test);
			wp_send_json_error('Still not working: ' . $msg['title'] . '. ' . $msg['help']);
		}
		Reg_Sync_Service::I()->unblock();
		wp_send_json_success(['message' => 'Resumed. Waiting changes are being sent.', 'html' => $this->overview_html()]);
	}

	/**
	 * Row action / product screen: queue the product and work the engine briefly, so the label
	 * usually shows the real answer by the time this returns.
	 */
	public function ajax_product_sync()
	{
		$this->guard('edit_products');

		$product_id = absint($_POST['product_id'] ?? 0);
		if (! $product_id || ! wc_get_product($product_id)) {
			wp_send_json_error('Product not found.');
		}

		Reg_Sync_Queue::I()->enqueue_product_and_children($product_id, 'update');
		if (! Reg_Sync_DB::I()->get_active_full_sync_run()) {
			Reg_Sync_Service::I()->work(15);
		}

		$status = $this->product_status($product_id);
		wp_send_json_success(['html' => Reg_UI::pill($status['label'], $status['tone'], $status['reason']), 'reason' => $status['reason']]);
	}
}
