<?php

/**
 * Regolith UI Shell
 *
 * Everything the admin screens share:
 * - the "Regolith" menu (Overview, Needs Attention, Settings + the hidden Setup wizard)
 * - CSS / JS loading (assets/admin.css, assets/admin.js), only on screens that need them
 * - the branded page header and small helpers (status pills, "x minutes ago")
 * - first-run redirect into the wizard, and the few admin notices we allow ourselves
 *
 * Branding (from regolithapp.com, Oct 2026): Aileron, near-black / deep green-charcoal hero,
 * mint call-to-action (#9CFEEC on #0A2029 text), teal accent (#02977D), 6px radius.
 * The CSS variables live at the top of assets/admin.css.
 *
 * Screen map:
 *   regolith            Overview            (Reg_Dashboard::render_overview)
 *   regolith-attention  Needs Attention     (Reg_Dashboard::render_attention)
 *   regolith-settings   Settings, tabbed    (Reg_Admin::render_settings_page)
 *   regolith-setup      Setup wizard        (Reg_Wizard::render), hidden from the menu
 *   regolith-logs       API logs, dev only  (Reg_Logger), hidden from the menu
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_UI
{
	const CAPABILITY = 'manage_options';

	/**
	 * Slugs per screen, so URLs are built in one place.
	 */
	const PAGES = [
		'overview'  => 'regolith',
		'attention' => 'regolith-attention',
		'settings'  => 'regolith-settings',
		'setup'     => 'regolith-setup',
		'logs'      => 'regolith-logs',
	];

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
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_filter('admin_body_class', [$this, 'body_class']);
		add_action('admin_init', [$this, 'maybe_redirect_after_activation']);
		add_action('admin_notices', [$this, 'render_notices']);
		add_filter('plugin_action_links_' . plugin_basename(REGOLITH_PLUGIN_FILE), [$this, 'plugin_action_links']);
	}

	// MARK: URLs

	/**
	 * Admin URL of one of our screens.
	 *
	 * @param string $page Key of self::PAGES.
	 * @param array  $args Extra query args.
	 */
	public static function url($page = 'overview', array $args = [])
	{
		$slug = self::PAGES[$page] ?? self::PAGES['overview'];
		return add_query_arg(array_merge(['page' => $slug], $args), admin_url('admin.php'));
	}

	public static function asset_url($path)
	{
		return plugins_url('assets/' . ltrim($path, '/'), REGOLITH_PLUGIN_FILE);
	}

	/**
	 * Is the current request one of our screens?
	 */
	public static function is_our_screen()
	{
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		return in_array($page, self::PAGES, true);
	}

	// MARK: Menu

	public function register_menu()
	{
		$attention = Reg_Sync_DB::I()->count_rejected_products();
		$bubble    = $attention ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $attention . '</span></span>' : '';

		add_menu_page(
			'Regolith',
			'Regolith',
			self::CAPABILITY,
			self::PAGES['overview'],
			[Reg_Dashboard::I(), 'render_overview'],
			$this->menu_icon(),
			56
		);

		$overview_hook = add_submenu_page(self::PAGES['overview'], 'Regolith Overview', 'Overview', self::CAPABILITY, self::PAGES['overview'], [Reg_Dashboard::I(), 'render_overview']);
		add_submenu_page(self::PAGES['overview'], 'Needs Attention', 'Needs Attention' . $bubble, self::CAPABILITY, self::PAGES['attention'], [Reg_Dashboard::I(), 'render_attention']);
		add_submenu_page(self::PAGES['overview'], 'Regolith Settings', 'Settings', self::CAPABILITY, self::PAGES['settings'], [Reg_Admin::I(), 'render_settings_page']);

		// Hidden screens: registered with an empty parent so they have a URL but no menu entry.
		$setup_hook = add_submenu_page('', 'Set up Regolith', 'Set up Regolith', self::CAPABILITY, self::PAGES['setup'], [Reg_Wizard::I(), 'render']);

		// WordPress can't find a title for menu-less pages (admin-header.php then passes null to
		// strip_tags and the tab says "‹ Site"). Give them one before the header renders.
		foreach ([$setup_hook => 'Set up Regolith', 'admin_page_' . self::PAGES['logs'] => 'Regolith API logs'] as $hook => $page_title) {
			add_action('load-' . $hook, static function () use ($page_title) {
				$GLOBALS['title'] = $page_title;
			});
		}

		// Nobody should land on the Overview of an unfinished setup, send them to the wizard.
		// Done on load-{hook} so the redirect happens before any output.
		add_action('load-' . $overview_hook, static function () {
			if (! Reg_Wizard::I()->is_complete()) {
				wp_safe_redirect(self::url('setup'));
				exit;
			}
		});
	}

	/**
	 * The Regolith mark (logo without the word) as a data-URI. WordPress repaints the fill to
	 * match the admin colour scheme, so it's black here on purpose.
	 */
	private function menu_icon()
	{
		$svg = @file_get_contents(plugin_dir_path(REGOLITH_PLUGIN_FILE) . 'assets/img/regolith-mark.svg');
		return $svg ? 'data:image/svg+xml;base64,' . base64_encode($svg) : 'dashicons-layout';
	}

	public function plugin_action_links($links)
	{
		$label = Reg_Wizard::I()->is_complete() ? 'Settings' : 'Set up';
		$url   = Reg_Wizard::I()->is_complete() ? self::url('settings') : self::url('setup');
		array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>');
		return $links;
	}

	// MARK: Assets

	public function enqueue_assets($hook)
	{
		$screen       = get_current_screen();
		$product_list = $screen && 'edit-product' === $screen->id;
		$product_edit = $screen && 'product' === $screen->id;

		if (! self::is_our_screen() && ! $product_list && ! $product_edit) {
			return;
		}

		$ver = REGOLITH_PLUGIN_VERSION . '.' . (int) @filemtime(plugin_dir_path(REGOLITH_PLUGIN_FILE) . 'assets/admin.js');

		wp_enqueue_style('regolith-admin', self::asset_url('admin.css'), [], $ver);
		wp_enqueue_script('regolith-admin', self::asset_url('admin.js'), ['jquery'], $ver, true);
		wp_localize_script('regolith-admin', 'regolithAdmin', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce('regolith_admin'),
			'urls'    => [
				'overview'  => self::url('overview'),
				'attention' => self::url('attention'),
				'settings'  => self::url('settings'),
				'setup'     => self::url('setup'),
			],
		]);
	}

	/**
	 * The wizard is full-screen: the WordPress side menu is hidden with CSS keyed on this class.
	 */
	public function body_class($classes)
	{
		if (self::is_our_screen()) {
			$classes .= ' regolith-screen';
		}
		if (isset($_GET['page']) && self::PAGES['setup'] === $_GET['page']) {
			$classes .= ' regolith-fullscreen';
		}
		return $classes;
	}

	// MARK: First Run

	/**
	 * Right after activation, take the admin straight into the wizard (once).
	 * Skipped for bulk activations, network admin and AJAX, like WooCommerce does it.
	 */
	public function maybe_redirect_after_activation()
	{
		if (! get_transient('regolith_activation_redirect')) {
			return;
		}
		delete_transient('regolith_activation_redirect');

		if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || ! current_user_can(self::CAPABILITY)) {
			return;
		}
		if (Reg_Wizard::I()->is_complete()) {
			return;
		}

		wp_safe_redirect(self::url('setup'));
		exit;
	}

	// MARK: Notices

	/**
	 * Only three notices outside our own screens, and only when something is actually needed:
	 * setup unfinished, sync paused, categories waiting for a decision.
	 */
	public function render_notices()
	{
		if (! current_user_can(self::CAPABILITY) || self::is_our_screen()) {
			return;
		}

		$screen = get_current_screen();

		if (! Reg_Wizard::I()->is_complete()) {
			// Only where a store owner would look, not on every admin page.
			if ($screen && in_array($screen->id, ['dashboard', 'plugins', 'edit-product', 'woocommerce_page_wc-admin'], true)) {
				$this->notice('info', 'Regolith isn\'t set up yet. It takes about 10 minutes.', self::url('setup'), 'Continue setup');
			}
			return;
		}

		$blocked = Reg_Sync_Service::I()->get_block();
		if ($blocked) {
			$msg = Reg_Messages::get($blocked['code'], $blocked['message']);
			$this->notice('error', 'Regolith sync is paused: ' . $msg['title'] . '.', self::url('overview'), 'See what to do');
			return;
		}

		if ('AttentionRequired' === get_option('regolith_onboarding_status')) {
			$this->notice('warning', 'Regolith: a new category needs a decision before its products can be listed.', self::url('settings', ['tab' => 'categories']), 'Review categories');
		}
	}

	private function notice($type, $text, $url, $label)
	{
		printf(
			'<div class="notice notice-%1$s regolith-notice"><p><strong>%2$s</strong> <a href="%3$s" class="button button-small" style="margin-left:6px;">%4$s</a></p></div>',
			esc_attr($type),
			esc_html($text),
			esc_url($url),
			esc_html($label)
		);
	}

	// MARK: Building Blocks

	/**
	 * Branded header with the logo and the section nav. Every post-setup screen starts here.
	 *
	 * @param string $current Key of the active nav item.
	 */
	public static function header($current = 'overview')
	{
		$attention = Reg_Sync_DB::I()->count_rejected_products();
		$items     = [
			'overview'  => 'Overview',
			'attention' => 'Needs attention' . ($attention ? ' (' . $attention . ')' : ''),
			'settings'  => 'Settings',
		];
		?>
		<div class="reg-header">
			<div class="reg-header__inner">
				<img class="reg-header__logo" src="<?php echo esc_url(self::asset_url('img/regolith-logo-white.svg')); ?>" alt="Regolith" width="138" height="40">
				<nav class="reg-header__nav">
					<?php foreach ($items as $key => $label) : ?>
						<a href="<?php echo esc_url(self::url($key)); ?>" class="<?php echo $key === $current ? 'is-active' : ''; ?>"><?php echo esc_html($label); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
		</div>
		<?php
	}

	/**
	 * Small coloured status label.
	 *
	 * @param string $label
	 * @param string $tone  ok | warn | error | info | muted
	 * @param string $title Tooltip.
	 */
	public static function pill($label, $tone = 'muted', $title = '')
	{
		return sprintf(
			'<span class="reg-pill reg-pill--%1$s"%3$s>%2$s</span>',
			esc_attr($tone),
			esc_html($label),
			$title ? ' title="' . esc_attr($title) . '"' : ''
		);
	}

	/**
	 * "5 minutes ago" from a UTC MySQL datetime.
	 */
	public static function ago($gmt)
	{
		if (! $gmt) {
			return 'never';
		}
		$ts = strtotime($gmt . ' UTC');
		return $ts ? human_time_diff($ts, time()) . ' ago' : 'never';
	}
}
