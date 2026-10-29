<?php

/**
 * Setup Wizard
 *
 * The whole onboarding for a non-technical store owner, full-screen, one decision per step:
 *
 *   welcome     what this is, what you need
 *   connect     paste the API key (registers / reconnects the store)
 *   categories  match every WooCommerce category or mark it "Don't list"; then the product
 *               list (membership snapshot) is sent in the background
 *   conflicts   only when products sit in categories pointing at different Regolith categories
 *   details     where the store keeps each product detail (required, see Reg_Field_Mapper)
 *   review      checklist + what will be listed, then "Go live"
 *   live        first Full sync progress, then the result
 *
 * "Safe" go-live: every category decided, every conflict resolved, details confirmed. The
 * server enforces the first two on onboarding/complete anyway, we just don't let people walk
 * into that error.
 *
 * Steps are derived from real state (options + GET category-mappings), never from "the user
 * clicked next", so leaving half way and coming back lands on the right step.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Wizard
{
	const STEPS = [
		'welcome'    => 'Welcome',
		'connect'    => 'Connect',
		'categories' => 'Categories',
		'conflicts'  => 'Product categories',
		'details'    => 'Product details',
		'review'     => 'Go live',
	];

	/**
	 * Mapping state for this request (one API call per page load).
	 *
	 * @var array|WP_Error|null
	 */
	private $state = null;

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
		add_action('wp_ajax_regolith_wizard_connect', [$this, 'ajax_connect']);
		add_action('wp_ajax_regolith_wizard_memberships', [$this, 'ajax_memberships']);
		add_action('wp_ajax_regolith_wizard_go_live', [$this, 'ajax_go_live']);
		add_action('wp_ajax_regolith_wizard_progress', [$this, 'ajax_progress']);
	}

	// MARK: State

	public function is_complete()
	{
		return (bool) get_option('regolith_setup_complete', 0);
	}

	/**
	 * Forget the setup progress so the wizard starts over (part of "Nuke / Reset All").
	 *
	 * Kept on purpose: API key, server address, store number and above all the store identity
	 * (regolith_external_store_id), losing that makes Regolith see a different store.
	 * Category / membership snapshots are flagged dirty so the wizard re-sends both.
	 */
	public function reset_setup()
	{
		foreach ([
			'regolith_setup_complete',
			'regolith_fields_confirmed',
			'regolith_field_map',
			'regolith_initial_sync_id',
			'regolith_fields_changed_at',
			'regolith_sync_blocked',
			Reg_Onboarding::STATE_OPTION,
		] as $option) {
			delete_option($option);
		}
		delete_transient('regolith_field_sources');

		update_option('regolith_categories_dirty', 1, false);
		update_option('regolith_memberships_dirty', 1, false);
	}

	public function is_connected()
	{
		return Reg_API::I()->get_store_id() && get_option('regolith_api_key') && get_option('regolith_api_url');
	}

	private function state()
	{
		if (null === $this->state) {
			$this->state = $this->is_connected() ? Reg_Category_Mapper::I()->get_state() : new WP_Error('regolith_not_connected', 'Not connected.');
		}
		return $this->state;
	}

	/**
	 * Which steps are done, from real state.
	 */
	public function progress()
	{
		$state = $this->state();
		$ok    = ! is_wp_error($state);
		$pcm   = Reg_Onboarding::I()->get_membership_state();

		$categories = $ok
			&& Reg_Category_Mapper::count_undecided($state) === 0
			&& ! empty($state['vendorCategories'])
			&& 'complete' === $pcm['status']
			&& ! Reg_Onboarding::I()->is_dirty('memberships');

		return [
			'welcome'    => true,
			'connect'    => $this->is_connected() && $ok,
			'categories' => $categories,
			'conflicts'  => $categories && Reg_Category_Mapper::count_pending_selections($state) === 0,
			'details'    => Reg_Field_Mapper::I()->is_confirmed(),
			'review'     => $this->is_complete(),
		];
	}

	/**
	 * Where to send someone who opens the wizard without ?step.
	 */
	private function first_open_step(array $progress)
	{
		if (! $this->is_connected()) {
			return 'welcome';
		}
		foreach (array_keys(self::STEPS) as $step) {
			if (empty($progress[$step])) {
				return $step;
			}
		}
		return 'review';
	}

	// MARK: Page

	public function render()
	{
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_die(__('You do not have permission to access this page.', 'regolith-integration'));
		}

		$requested = isset($_GET['step']) ? sanitize_key(wp_unslash($_GET['step'])) : '';

		// After go-live the wizard shows the first sync (or its result) unless a step is asked for.
		if ('live' === $requested || ('' === $requested && $this->is_complete())) {
			$this->render_shell('live', [], function () {
				$this->step_live();
			});
			return;
		}

		$progress = $this->progress();
		$step     = isset(self::STEPS[$requested]) ? $requested : $this->first_open_step($progress);

		// Can't skip ahead past an unfinished step (except back to anything already done).
		$keys = array_keys(self::STEPS);
		foreach ($keys as $key) {
			if ($key === $step) {
				break;
			}
			if (empty($progress[$key]) && 'welcome' !== $key) {
				$step = $key;
				break;
			}
		}

		$this->render_shell($step, $progress, function () use ($step) {
			$method = 'step_' . $step;
			$this->$method();
		});
	}

	/**
	 * Full-screen frame: logo bar, stepper, card.
	 */
	private function render_shell($current, array $progress, callable $body)
	{
		$keys = array_keys(self::STEPS);
		$pos  = array_search($current, $keys, true);
		?>
		<div class="reg-wizard" data-reg-wizard data-step="<?php echo esc_attr($current); ?>">
			<header class="reg-wizard__top">
				<img src="<?php echo esc_url(Reg_UI::asset_url('img/regolith-logo-white.svg')); ?>" alt="Regolith" width="138" height="40">
				<a class="reg-wizard__exit" href="<?php echo esc_url($this->is_complete() ? Reg_UI::url('overview') : admin_url()); ?>">
					<?php echo $this->is_complete() ? 'Go to Regolith overview' : 'Save and exit'; ?>
				</a>
			</header>

			<?php if ('live' !== $current) : ?>
				<ol class="reg-stepper">
					<?php foreach (self::STEPS as $key => $label) :
						$index = array_search($key, $keys, true);
						$class = $key === $current ? 'is-current' : (! empty($progress[$key]) ? 'is-done' : ($index < $pos ? 'is-done' : ''));
						$link  = ! empty($progress[$key]) || $index <= $pos;
						?>
						<li class="<?php echo esc_attr($class); ?>">
							<?php if ($link && $key !== $current) : ?>
								<a href="<?php echo esc_url(Reg_UI::url('setup', ['step' => $key])); ?>"><span class="reg-stepper__dot"></span><?php echo esc_html($label); ?></a>
							<?php else : ?>
								<span><span class="reg-stepper__dot"></span><?php echo esc_html($label); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<main class="reg-wizard__card <?php echo in_array($current, ['details', 'categories'], true) ? 'reg-wizard__card--wide' : ''; ?>">
				<?php $body(); ?>
			</main>

			<p class="reg-wizard__help">Need a hand? <a href="<?php echo esc_url(Reg_Messages::SUPPORT_URL); ?>" target="_blank" rel="noopener">Book a call with Regolith support</a></p>
		</div>
		<?php
	}

	/**
	 * Previous / next buttons at the bottom of a step.
	 */
	private function nav($back, $next_label = 'Continue', $next_attrs = '')
	{
		?>
		<div class="reg-wizard__nav">
			<?php if ($back) : ?>
				<a class="reg-btn reg-btn--ghost" href="<?php echo esc_url(Reg_UI::url('setup', ['step' => $back])); ?>">Back</a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>
			<span class="reg-wizard__status" data-reg-status></span>
			<?php if ($next_label) : ?>
				<button type="button" class="reg-btn reg-btn--primary" <?php echo $next_attrs; // built from literals only ?>><?php echo esc_html($next_label); ?></button>
			<?php endif; ?>
		</div>
		<?php
	}

	private function state_error()
	{
		$state = $this->state();
		if (! is_wp_error($state)) {
			return false;
		}
		$msg = Reg_Messages::from_error($state);
		?>
		<div class="reg-alert reg-alert--error">
			<strong><?php echo esc_html($msg['title']); ?></strong> <?php echo esc_html($msg['help']); ?>
			<a href="<?php echo esc_url(Reg_UI::url('setup', ['step' => 'connect'])); ?>">Check the connection</a>
		</div>
		<?php
		return true;
	}

	// MARK: Steps

	private function step_welcome()
	{
		?>
		<div class="reg-hero">
			<h1>Let's get your products on Regolith</h1>
			<p class="reg-lead">This takes about 10 minutes. You can leave at any point and pick up where you left off.</p>
		</div>

		<ol class="reg-checklist">
			<li><strong>Connect your Regolith account</strong><span>Paste the API key from your Regolith dashboard.</span></li>
			<li><strong>Match your categories</strong><span>Tell us where each of your categories belongs on Regolith.</span></li>
			<li><strong>Check your product details</strong><span>Show us where your store keeps brand, model, specs and so on.</span></li>
			<li><strong>Go live</strong><span>We send your catalog and keep it up to date automatically from then on.</span></li>
		</ol>

		<div class="reg-alert reg-alert--info">
			<strong>You'll need your Regolith API key.</strong>
			Log in to your <a href="<?php echo esc_url(Reg_Messages::DASHBOARD_URL); ?>" target="_blank" rel="noopener">Regolith dashboard</a>, open <em>API keys</em> and create an <em>Integration</em> key.
		</div>

		<div class="reg-wizard__nav">
			<span></span><span></span>
			<a class="reg-btn reg-btn--primary" href="<?php echo esc_url(Reg_UI::url('setup', ['step' => 'connect'])); ?>">Get started</a>
		</div>
		<?php
	}

	private function step_connect()
	{
		$connected = $this->is_connected() && ! is_wp_error($this->state());
		$url       = get_option('regolith_api_url', '');
		$key       = get_option('regolith_api_key', '');
		$store     = $connected ? ($this->state()['store'] ?? []) : [];
		?>
		<h1>Connect your Regolith account</h1>

		<?php if ($connected) : ?>
			<div class="reg-alert reg-alert--ok">
				<strong>Connected.</strong>
				<?php echo esc_html(sprintf('This store (%s) is linked to Regolith as store #%d.', $store['siteUrl'] ?? home_url(), Reg_API::I()->get_store_id())); ?>
			</div>
		<?php else : ?>
			<p class="reg-lead">Paste your Integration API key below. We'll check it and link this store to your Regolith account.</p>
		<?php endif; ?>

		<form class="reg-form" data-reg-connect-form <?php echo $connected ? 'hidden' : ''; ?>>
			<label class="reg-label" for="reg-api-key">API key</label>
			<div class="reg-input-group">
				<input class="reg-input" type="password" id="reg-api-key" name="api_key" autocomplete="off" placeholder="rg_…" value="">
				<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-toggle-secret="#reg-api-key">Show</button>
			</div>
			<?php if ($key) : ?><p class="reg-muted">A key ending in …<?php echo esc_html(substr($key, -4)); ?> is saved. Leave empty to use it.</p><?php endif; ?>

			<details class="reg-disclosure" <?php echo $url ? '' : 'open'; ?>>
				<summary>Regolith server address</summary>
				<label class="reg-label" for="reg-api-url">Server address</label>
				<input class="reg-input" type="url" id="reg-api-url" name="api_url" value="<?php echo esc_attr($url); ?>" placeholder="https://…">
				<p class="reg-muted">Provided by Regolith together with your key. Leave as is unless they told you otherwise.</p>
			</details>
		</form>

		<p class="reg-muted">Your store is registered with its web address, <strong><?php echo esc_html(untrailingslashit(home_url())); ?></strong>. Regolith can't change that later, so connect from your live store.</p>

		<?php if ($connected) : ?>
			<p><a href="#" data-reg-show-connect>Use a different API key</a></p>
			<?php $this->nav('welcome', 'Continue', 'data-reg-go="categories"'); ?>
		<?php else : ?>
			<?php $this->nav('welcome', 'Connect', 'data-reg-connect'); ?>
		<?php endif; ?>
		<?php
	}

	private function step_categories()
	{
		?>
		<h1>Match your categories</h1>
		<p class="reg-lead">For each of your WooCommerce categories, choose where those products belong on Regolith, or that they shouldn't be listed at all. We've suggested matches where the names line up.</p>
		<?php
		if ($this->state_error()) {
			return;
		}

		Reg_Category_Mapper::I()->render_mapping($this->state(), 'wizard');
		?>
		<div class="reg-overlay" data-reg-overlay hidden>
			<div class="reg-overlay__box">
				<div class="reg-spinner"></div>
				<strong data-reg-overlay-title>Saving your categories…</strong>
				<progress max="100" value="0" data-reg-overlay-progress></progress>
				<span class="reg-muted" data-reg-overlay-text></span>
			</div>
		</div>
		<?php
		$this->nav('connect', 'Save and continue', 'data-reg-save-categories');
	}

	private function step_conflicts()
	{
		?>
		<h1>Some products fit more than one category</h1>
		<p class="reg-lead">These products are in several of your categories, and those point to different Regolith categories. Pick where each one should appear. Variations follow their product.</p>
		<?php
		if ($this->state_error()) {
			return;
		}

		$pending = Reg_Category_Mapper::count_pending_selections($this->state());
		Reg_Category_Mapper::I()->render_selections($this->state());

		$pending
			? $this->nav('categories', 'Save and continue', 'data-reg-save-selections')
			: $this->nav('categories', 'Continue', 'data-reg-go="details"');
	}

	private function step_details()
	{
		?>
		<h1>Where does your store keep product details?</h1>
		<p class="reg-lead">Buyers on Regolith filter by brand, model, memory, warranty and more. Stores keep these in all sorts of places, so tell us where yours are. We've filled in what we could recognise. Use the preview on the right to check real products.</p>
		<p class="reg-muted">You don't need to fill everything: leave fields that don't apply to your products as "Not on my store". If the same detail lives in two places, set a fallback.</p>
		<?php
		Reg_Field_Mapper::I()->render_form('wizard');
		$this->nav($this->progress()['conflicts'] ? 'conflicts' : 'categories', 'Looks right, continue', 'data-reg-save-fields');
	}

	private function step_review()
	{
		if ($this->state_error()) {
			return;
		}

		$state    = $this->state();
		$progress = $this->progress();
		$vendor   = array_filter((array) ($state['vendorCategories'] ?? []), static fn ($c) => ($c['isSourceActive'] ?? true));
		$mapped   = count(array_filter($vendor, static fn ($c) => 'Mapped' === ($c['decision'] ?? '')));
		$hidden   = array_values(array_filter($vendor, static fn ($c) => 'Ignored' === ($c['decision'] ?? '')));
		$summary  = $this->catalog_summary(array_map(static fn ($c) => (string) $c['externalCategoryId'], $hidden));
		$ready    = $progress['connect'] && $progress['categories'] && $progress['conflicts'] && $progress['details'];
		$checks   = [
			'connect'    => ['Connected to Regolith', 'Connect'],
			'categories' => [sprintf('%d categories matched, %d not listed', $mapped, count($hidden)), 'Finish categories'],
			'conflicts'  => ['Every product has one clear category', 'Resolve products'],
			'details'    => [sprintf('%d product details mapped', Reg_Field_Mapper::I()->count_mapped()), 'Review product details'],
		];
		?>
		<h1>Ready to go live?</h1>

		<ul class="reg-review">
			<?php foreach ($checks as $key => [$text, $fix]) : ?>
				<li class="<?php echo $progress[$key] ? 'is-ok' : 'is-missing'; ?>">
					<span><?php echo esc_html($text); ?></span>
					<a href="<?php echo esc_url(Reg_UI::url('setup', ['step' => $key])); ?>"><?php echo $progress[$key] ? 'Change' : esc_html($fix); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="reg-stats">
			<div class="reg-stat"><span class="reg-stat__num"><?php echo (int) $summary['items']; ?></span><span>items will be sent<br><small class="reg-muted">each variation counts as one</small></span></div>
			<div class="reg-stat"><span class="reg-stat__num"><?php echo (int) $summary['listable']; ?></span><span>should go live right away</span></div>
			<div class="reg-stat reg-stat--muted"><span class="reg-stat__num"><?php echo (int) ($summary['items'] - $summary['listable']); ?></span><span>won't be listed yet</span></div>
		</div>

		<?php if ($summary['items'] > $summary['listable']) : ?>
			<details class="reg-disclosure">
				<summary>Why some items won't be listed</summary>
				<ul class="reg-bullets">
					<?php foreach ($summary['reasons'] as $reason => $count) : ?>
						<?php if ($count) : ?><li><?php echo esc_html($count . ' ' . $reason); ?></li><?php endif; ?>
					<?php endforeach; ?>
				</ul>
				<p class="reg-muted">That's normal. They're sent anyway and go live on their own as soon as they qualify (back in stock, published, priced).</p>
			</details>
		<?php endif; ?>

		<p class="reg-muted">After going live, every change you make in WooCommerce (prices, stock, new products) reaches Regolith within a few minutes. Regolith then checks and imports each product, including its image, so the first sync can take a while for a big catalog.</p>
		<?php
		$this->nav('details', $ready ? 'Go live' : '', $ready ? 'data-reg-go-live' : '');
		if (! $ready) {
			echo '<p class="reg-alert reg-alert--warn">Finish the steps marked above first.</p>';
		}
	}

	private function step_live()
	{
		$sync_id = get_option('regolith_initial_sync_id', '');
		?>
		<div class="reg-live" data-reg-live data-sync-id="<?php echo esc_attr($sync_id); ?>">
			<div class="reg-live__running" data-reg-live-running>
				<div class="reg-spinner reg-spinner--large"></div>
				<h1 data-reg-live-title>Sending your catalog to Regolith…</h1>
				<progress max="100" value="0" data-reg-live-progress></progress>
				<p class="reg-lead" data-reg-live-text>Getting started.</p>
				<p class="reg-muted">You can close this page. Syncing carries on in the background.</p>
			</div>
			<div class="reg-live__done" data-reg-live-done hidden>
				<div class="reg-big-check" aria-hidden="true"></div>
				<h1>You're live on Regolith</h1>
				<div class="reg-stats" data-reg-live-stats></div>
				<p class="reg-lead">From now on, changes in your store reach Regolith automatically.</p>
				<div class="reg-wizard__nav">
					<a class="reg-btn reg-btn--ghost" href="<?php echo esc_url(Reg_UI::url('attention')); ?>" data-reg-live-attention hidden>Review products that need attention</a>
					<span></span>
					<a class="reg-btn reg-btn--primary" href="<?php echo esc_url(Reg_UI::url('overview')); ?>">Go to overview</a>
				</div>
			</div>
		</div>
		<?php
	}

	// MARK: Catalog Summary

	/**
	 * Rough local forecast for the review step. The server decides for real; this just sets
	 * expectations so "126 of 150 listed" doesn't come as a surprise.
	 *
	 * @param string[] $hidden_category_ids WooCommerce term ids marked "Don't list".
	 */
	public function catalog_summary(array $hidden_category_ids)
	{
		global $wpdb;

		$statuses = Reg_Product_Serializer::CATALOG_STATUSES;
		$ids      = $wpdb->get_col($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ')',
			...$statuses
		));

		$hidden  = array_flip(array_map('intval', $hidden_category_ids));
		$out     = ['items' => 0, 'listable' => 0, 'reasons' => [
			'are in categories marked "Don\'t list"' => 0,
			'aren\'t published'                       => 0,
			'are out of stock'                        => 0,
			'have no price'                           => 0,
			'can\'t be bought (e.g. external / affiliate products)' => 0,
		]];

		foreach ($ids as $id) {
			$product = wc_get_product($id);
			if (! $product || $product->is_type('grouped')) {
				continue;
			}

			$cats       = array_map('intval', wp_get_post_terms($id, 'product_cat', ['fields' => 'ids']) ?: []);
			$all_hidden = $cats && ! array_diff($cats, array_keys($hidden));
			$published  = 'publish' === $product->get_status();
			$units      = $product->is_type('variable') ? array_filter(array_map('wc_get_product', $product->get_children())) : [$product];

			foreach ($units as $unit) {
				$out['items']++;
				if ($all_hidden) {
					$out['reasons']['are in categories marked "Don\'t list"']++;
				} elseif (! $published || 'publish' !== $unit->get_status()) {
					$out['reasons']['aren\'t published']++;
				} elseif (! $unit->is_in_stock()) {
					$out['reasons']['are out of stock']++;
				} elseif ('' === (string) $unit->get_price() || (float) $unit->get_price() <= 0) {
					$out['reasons']['have no price']++;
				} elseif (! $unit->is_purchasable()) {
					$out['reasons']['can\'t be bought (e.g. external / affiliate products)']++;
				} else {
					$out['listable']++;
				}
			}
		}

		return $out;
	}

	// MARK: AJAX

	private function guard()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}
	}

	private function fail_with(WP_Error $error)
	{
		$msg = Reg_Messages::from_error($error);
		wp_send_json_error($msg['title'] . '. ' . $msg['help']);
	}

	/**
	 * Save the key (and server address), then register / reconnect.
	 */
	public function ajax_connect()
	{
		$this->guard();

		$key = trim(sanitize_text_field(wp_unslash($_POST['api_key'] ?? '')));
		$url = esc_url_raw(trim(wp_unslash($_POST['api_url'] ?? '')));

		if ('' !== $key) {
			update_option('regolith_api_key', $key);
		}
		if ('' !== $url) {
			update_option('regolith_api_url', untrailingslashit($url));
		}

		if (! get_option('regolith_api_key')) {
			wp_send_json_error('Paste your API key first.');
		}
		if (! get_option('regolith_api_url')) {
			wp_send_json_error('Enter the Regolith server address (it came with your API key).');
		}

		$result = Reg_API::I()->test_connection();
		if (is_wp_error($result)) {
			$this->fail_with($result);
		}

		wp_send_json_success(['next' => Reg_UI::url('setup', ['step' => 'categories'])]);
	}

	/**
	 * Make sure Regolith has a complete, current product list (membership snapshot). Runs in
	 * slices of ~15s, the page keeps calling until it says complete.
	 */
	public function ajax_memberships()
	{
		$this->guard();

		$onboarding = Reg_Onboarding::I();
		$state      = $onboarding->get_membership_state();

		if ('complete' === $state['status'] && ! $onboarding->is_dirty('memberships')) {
			$result = ['status' => 'complete', 'state' => $state];
		} else {
			if ('running' !== $state['status']) {
				$onboarding->start_membership_snapshot();
			}
			$result = $onboarding->run_membership_snapshot(15);
		}

		$payload = [
			'status'   => $result['status'],
			'sent'     => (int) $result['state']['sent'],
			'total'    => (int) $result['state']['total'],
			'retry_in' => (int) ($result['retry_in'] ?? 0),
			'message'  => $result['message'] ?? '',
		];

		if ('complete' === $result['status']) {
			$data = Reg_Category_Mapper::I()->get_state();
			$pending = is_wp_error($data) ? 0 : Reg_Category_Mapper::count_pending_selections($data);
			$payload['next'] = Reg_UI::url('setup', ['step' => $pending ? 'conflicts' : 'details']);
		}

		if ('failed' === $result['status']) {
			$code = strtok((string) $result['state']['last_error'], ':');
			$msg  = Reg_Messages::get($code, (string) $result['state']['last_error']);
			$payload['message'] = $msg['title'] . '. ' . $msg['help'];
		}

		wp_send_json_success($payload);
	}

	/**
	 * Finish onboarding on Regolith, then start the first Full sync.
	 */
	public function ajax_go_live()
	{
		$this->guard();

		$progress = $this->progress();
		foreach (['connect', 'categories', 'conflicts', 'details'] as $step) {
			if (empty($progress[$step])) {
				wp_send_json_error('One of the setup steps isn\'t finished yet. Reload the page to see which.');
			}
		}

		$done = Reg_API::I()->complete_onboarding();
		if (is_wp_error($done)) {
			$this->fail_with($done);
		}

		$run = Reg_Sync_Service::I()->start_full_sync();
		if (is_wp_error($run)) {
			// Already running (double click, second tab): just follow that one.
			$active = Reg_Sync_DB::I()->get_active_full_sync_run();
			if (! $active) {
				$this->fail_with($run);
			}
			$run = ['sync_id' => $active['sync_id']];
		}

		update_option('regolith_initial_sync_id', $run['sync_id'], false);
		update_option('regolith_setup_complete', time());
		delete_option('regolith_fields_changed_at');

		wp_send_json_success(['next' => Reg_UI::url('setup', ['step' => 'live'])]);
	}

	/**
	 * Progress of the first Full sync. Also works the engine for a few seconds, so the sync
	 * moves while someone watches even if WP-Cron is slow.
	 */
	public function ajax_progress()
	{
		$this->guard();

		Reg_Sync_Service::I()->work(8);

		$run = Reg_Sync_DB::I()->get_sync_run_by_sync_id((string) get_option('regolith_initial_sync_id', ''));
		if (! $run) {
			$run = Reg_Sync_DB::I()->get_latest_sync_run('Full');
		}
		if (! $run) {
			wp_send_json_success(['done' => true, 'stats' => $this->result_stats()]);
		}

		$blocked = Reg_Sync_Service::I()->get_block();
		$active  = in_array($run['status'], ['Building', 'InProgress'], true);
		$text    = '';
		$percent = 0;

		if ($blocked) {
			$msg  = Reg_Messages::get($blocked['code'], $blocked['message']);
			$text = 'Paused: ' . $msg['title'] . '. ' . $msg['help'];
		} elseif ('Building' === $run['status']) {
			$text    = sprintf('Preparing your catalog: %d items so far.', (int) $run['total_items']);
			$percent = 5;
		} elseif ('InProgress' === $run['status']) {
			$total   = max(1, (int) $run['total_batches']);
			$done    = (int) $run['completed_batches'];
			$percent = 10 + (int) round(90 * $done / $total);
			$next    = Reg_Sync_DB::I()->get_next_batch_for_run($run['sync_id']);
			$text    = sprintf('Sent %d of %d items.', min((int) $run['total_items'], $done * Reg_Sync_Service::I()->batch_size()), (int) $run['total_items']);
			if ($next && 'retry_wait' === $next['status']) {
				$text .= ' Regolith is busy importing images, we\'ll continue in a moment.';
			}
		} elseif (in_array($run['status'], ['Failed', 'Abandoned'], true)) {
			$msg  = Reg_Messages::get((string) $run['last_error_code'], (string) $run['last_error_message']);
			$text = 'The first sync stopped: ' . $msg['title'] . '. It will be retried automatically, or start it again from the overview.';
		}

		wp_send_json_success([
			'done'    => ! $active,
			'failed'  => in_array($run['status'], ['Failed', 'Abandoned'], true),
			'percent' => $active ? $percent : 100,
			'text'    => $text,
			'stats'   => $active ? null : $this->result_stats(),
		]);
	}

	/**
	 * Numbers for the "you're live" screen, from the ledger.
	 */
	private function result_stats()
	{
		$totals = Reg_Sync_DB::I()->get_sync_totals();
		return [
			'live'      => (int) $totals['synced'],
			'unlisted'  => (int) ($totals['deactivated'] + $totals['skipped']),
			'attention' => (int) Reg_Sync_DB::I()->count_rejected_products(),
		];
	}
}
