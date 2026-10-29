<?php

/**
 * Settings
 *
 * One page, four tabs:
 * - Connection       key, server address, store identity, reconnect
 * - Categories       the same matching UI as the wizard (+ products in several categories)
 * - Product details  the same mapping UI as the wizard (+ live preview)
 * - Advanced         for support / developers: the raw sync engine panel, manual onboarding
 *                    steps, logs and the dev reset. Plain store owners never need it.
 *
 * Saving in any tab goes through the same AJAX endpoints the wizard uses, so there's one code
 * path per action.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Admin
{
	const TABS = [
		'connection' => 'Connection',
		'categories' => 'Categories',
		'details'    => 'Product details',
		'advanced'   => 'Advanced',
	];

	// MARK: Singleton Instance
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

	// MARK: Constructor Hooks
	public function __construct()
	{
		// Advanced tab tools.
		add_action('wp_ajax_regolith_adv_categories', [$this, 'ajax_adv_categories']);
		add_action('wp_ajax_regolith_adv_memberships', [$this, 'ajax_adv_memberships']);
		add_action('wp_ajax_regolith_adv_complete', [$this, 'ajax_adv_complete']);
		add_action('wp_ajax_regolith_adv_rerun_wizard', [$this, 'ajax_adv_rerun_wizard']);
	}

	// MARK: Page

	public function render_settings_page()
	{
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_die(__('You do not have permission to access this page.', 'regolith-integration'));
		}

		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
		if (! isset(self::TABS[$tab])) {
			$tab = 'connection';
		}
		?>
		<div class="reg-page">
			<?php Reg_UI::header('settings'); ?>
			<div class="reg-page__body">
				<h1 class="reg-title">Settings</h1>
				<nav class="reg-tabs">
					<?php foreach (self::TABS as $key => $label) : ?>
						<a class="<?php echo $key === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url(Reg_UI::url('settings', ['tab' => $key])); ?>"><?php echo esc_html($label); ?></a>
					<?php endforeach; ?>
				</nav>
				<div class="reg-tab-body">
					<?php
					$method = 'tab_' . $tab;
					$this->$method();
					?>
				</div>
			</div>
		</div>
		<?php
	}

	// MARK: Tabs

	private function tab_connection()
	{
		$key      = get_option('regolith_api_key', '');
		$url      = get_option('regolith_api_url', '');
		$store_id = Reg_API::I()->get_store_id();
		$status   = get_option('regolith_onboarding_status', '');
		$blocked  = Reg_Sync_Service::I()->get_block();
		?>
		<section class="reg-card">
			<h3>Regolith account</h3>
			<?php if ($blocked) :
				$msg = Reg_Messages::get($blocked['code'], $blocked['message']); ?>
				<div class="reg-alert reg-alert--error"><strong><?php echo esc_html($msg['title']); ?>.</strong> <?php echo esc_html($msg['help']); ?></div>
			<?php elseif ($store_id) : ?>
				<div class="reg-alert reg-alert--ok"><strong>Connected.</strong> <?php echo esc_html(sprintf('Store #%d, status: %s.', $store_id, $this->human_status($status))); ?></div>
			<?php else : ?>
				<div class="reg-alert reg-alert--warn"><strong>Not connected.</strong> <a href="<?php echo esc_url(Reg_UI::url('setup')); ?>">Run the setup</a>.</div>
			<?php endif; ?>

			<form class="reg-form" data-reg-connect-form data-reg-stay>
				<label class="reg-label" for="reg-api-key">API key</label>
				<div class="reg-input-group">
					<input class="reg-input" type="password" id="reg-api-key" name="api_key" autocomplete="new-password" placeholder="<?php echo $key ? esc_attr('Saved, ends with …' . substr($key, -4)) : 'rg_…'; ?>">
					<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-toggle-secret="#reg-api-key">Show</button>
				</div>
				<p class="reg-muted">Leave empty to keep the saved key. New keys are created in your <a href="<?php echo esc_url(Reg_Messages::DASHBOARD_URL); ?>" target="_blank" rel="noopener">Regolith dashboard</a>.</p>

				<details class="reg-disclosure">
					<summary>Regolith server address</summary>
					<input class="reg-input" type="url" name="api_url" value="<?php echo esc_attr($url); ?>" placeholder="https://…">
				</details>

				<div class="reg-form__actions">
					<button type="button" class="reg-btn reg-btn--primary" data-reg-connect>Save and test connection</button>
					<span class="reg-wizard__status" data-reg-status></span>
				</div>
			</form>
		</section>

		<section class="reg-card">
			<h3>This store</h3>
			<dl class="reg-dl">
				<dt>Web address</dt><dd><?php echo esc_html(untrailingslashit(home_url())); ?></dd>
				<dt>Regolith store number</dt><dd><?php echo $store_id ? (int) $store_id : '—'; ?></dd>
				<dt>Store identity</dt><dd><code><?php echo esc_html(get_option('regolith_external_store_id', '—')); ?></code></dd>
			</dl>
			<p class="reg-muted">Regolith ties your account to this web address and identity. Moving to a new domain, or replacing the store, needs Regolith support to unlink the old one first.</p>
		</section>
		<?php
	}

	private function tab_categories()
	{
		$data = Reg_Category_Mapper::I()->get_state();
		if (is_wp_error($data)) {
			$msg = Reg_Messages::from_error($data);
			echo '<div class="reg-alert reg-alert--error"><strong>' . esc_html($msg['title']) . '.</strong> ' . esc_html($msg['help']) . '</div>';
			return;
		}
		?>
		<section class="reg-card">
			<div class="reg-card__head">
				<div>
					<h3>Your categories on Regolith</h3>
					<p class="reg-muted">Changes apply to listed products right away. New WooCommerce categories show up here automatically and need a decision before their products are listed.</p>
				</div>
			</div>
			<?php Reg_Category_Mapper::I()->render_mapping($data); ?>
			<div class="reg-form__actions">
				<button type="button" class="reg-btn reg-btn--primary" data-reg-save-categories data-reg-stay>Save categories</button>
				<span class="reg-wizard__status" data-reg-status></span>
			</div>
		</section>

		<section class="reg-card">
			<h3>Products in several categories</h3>
			<?php Reg_Category_Mapper::I()->render_selections($data); ?>
			<?php if (Reg_Category_Mapper::count_pending_selections($data)) : ?>
				<div class="reg-form__actions">
					<button type="button" class="reg-btn reg-btn--primary" data-reg-save-selections data-reg-stay>Save choices</button>
					<span class="reg-wizard__status" data-reg-status></span>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private function tab_details()
	{
		?>
		<section class="reg-card">
			<h3>Where your store keeps product details</h3>
			<p class="reg-muted">Changes are used for every product sent from now on. To update everything already on Regolith, use "Send everything again" on the overview after saving.</p>
			<?php Reg_Field_Mapper::I()->render_form('settings'); ?>
			<div class="reg-form__actions">
				<button type="button" class="reg-btn reg-btn--primary" data-reg-save-fields data-reg-stay>Save product details</button>
				<span class="reg-wizard__status" data-reg-status></span>
			</div>
		</section>
		<?php
	}

	private function tab_advanced()
	{
		$pcm = Reg_Onboarding::I()->get_membership_state();
		?>
		<div class="reg-alert reg-alert--info">This tab is for Regolith support and developers. Nothing here is needed day to day.</div>

		<section class="reg-card">
			<h3>Sync engine</h3>
			<?php Reg_Sync_Admin::I()->render_panel(); ?>
		</section>

		<section class="reg-card">
			<h3>Onboarding steps (manual)</h3>
			<p class="reg-muted">The setup wizard runs these for you. Use them to re-send something by hand.</p>
			<div class="reg-button-row">
				<button type="button" class="reg-btn reg-btn--ghost" data-reg-action="regolith_adv_categories">Re-send category list</button>
				<button type="button" class="reg-btn reg-btn--ghost" data-reg-action="regolith_adv_memberships">Re-send product list (membership snapshot)</button>
				<button type="button" class="reg-btn reg-btn--ghost" data-reg-action="regolith_adv_complete">Complete onboarding</button>
				<button type="button" class="reg-btn reg-btn--ghost" data-reg-action="regolith_adv_rerun_wizard" data-confirm="Open the setup wizard again? Syncing keeps running meanwhile.">Run setup wizard again</button>
			</div>
			<dl class="reg-dl">
				<dt>Category list last sent</dt><dd><?php echo esc_html(get_option('regolith_latest_category_snapshot_id', 'never')); ?></dd>
				<dt>Product list</dt><dd><?php echo esc_html($pcm['status'] . ', ' . $pcm['sent'] . ' / ' . $pcm['total'] . ($pcm['completed_at'] ? ', completed ' . $pcm['completed_at'] . ' UTC' : '') . ($pcm['last_error'] ? ' (' . $pcm['last_error'] . ')' : '')); ?></dd>
				<dt>Onboarding status</dt><dd><?php echo esc_html(get_option('regolith_onboarding_status', 'unknown')); ?></dd>
				<dt>Dev mode</dt><dd><?php echo regolith_is_dev() ? 'On (placeholder images, HTTP log)' : 'Off'; ?></dd>
			</dl>
			<?php if (regolith_is_dev()) : ?>
				<p><a href="<?php echo esc_url(Reg_UI::url('logs')); ?>">Open API logs</a></p>
			<?php endif; ?>
		</section>

		<?php Reg_Updater::I()->render_panel(); ?>

		<?php
	}

	private function human_status($status)
	{
		$map = [
			'CategoryMappingRequired'          => 'categories need matching',
			'ProductCategorySelectionRequired' => 'some products need a category choice',
			'ReadyForInitialSync'              => 'ready for the first sync',
			'Active'                           => 'active',
			'AttentionRequired'                => 'a new category needs a decision',
			'Disconnected'                     => 'disconnected',
		];
		return $map[$status] ?? ($status ?: 'unknown');
	}

	// MARK: AJAX (Advanced)

	private function guard()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}
	}

	public function ajax_adv_categories()
	{
		$this->guard();
		$res = Reg_API::I()->sync_category_snapshot();
		if (is_wp_error($res)) {
			wp_send_json_error($res->get_error_message());
		}
		$data = Reg_API::data($res);
		wp_send_json_success(['message' => sprintf('Category list sent (%d categories).', (int) ($data['receivedCount'] ?? 0))]);
	}

	public function ajax_adv_memberships()
	{
		$this->guard();
		$state = Reg_Onboarding::I()->get_membership_state();
		if ('running' !== $state['status']) {
			Reg_Onboarding::I()->start_membership_snapshot();
		}
		$res = Reg_Onboarding::I()->run_membership_snapshot(20);
		$s   = $res['state'];
		if ('failed' === $res['status']) {
			wp_send_json_error('Failed: ' . $s['last_error']);
		}
		wp_send_json_success(['message' => sprintf('Product list: %s, %d / %d sent.%s', $res['status'], $s['sent'], $s['total'], 'complete' === $res['status'] ? '' : ' Click again to continue.')]);
	}

	public function ajax_adv_complete()
	{
		$this->guard();
		$res = Reg_API::I()->complete_onboarding();
		if (is_wp_error($res)) {
			wp_send_json_error($res->get_error_message());
		}
		wp_send_json_success(['message' => 'Onboarding status: ' . (Reg_API::data($res)['onboardingStatus'] ?? 'unknown') . '.']);
	}

	public function ajax_adv_rerun_wizard()
	{
		$this->guard();
		wp_send_json_success(['redirect' => Reg_UI::url('setup', ['step' => 'connect'])]);
	}
}
