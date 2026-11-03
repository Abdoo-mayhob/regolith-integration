<?php

/**
 * Regolith Environments (Live / Staging)
 *
 * A store can be connected to Regolith's Live server, its Staging server, or both, and switch
 * between them from the switch in the plugin header.
 *
 * Live and Staging are two different Regolith servers, so the things that only make sense for
 * ONE server are kept per server:
 *
 *   regolith_api_url              → regolith_api_url_live / regolith_api_url_staging
 *   regolith_api_key              → regolith_api_key_live / regolith_api_key_staging
 *   regolith_store_id             → …_live / …_staging   (each server numbers stores itself)
 *   regolith_onboarding_status    → …_live / …_staging
 *   regolith_setup_complete       → …_live / …_staging   (going live on Staging ≠ live on Live)
 *
 * The rest of the plugin keeps using the plain names. pre_option / pre_update_option filters
 * route every read, write and delete to the active server's copy, so no other class has to know
 * environments exist.
 *
 * Shared on purpose: the store identity (regolith_external_store_id, it identifies THIS
 * WordPress site, same on both servers) and the Product Details mapping (it describes the store,
 * not the server).
 *
 * Local sync history (ledger, queue, runs) is not split: it describes "the server we last talked
 * to". Switching is refused while a sync run is active, and after a switch the dashboard reflects
 * the new server as soon as products sync again (Nuke / Reset All gives a clean slate).
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Env
{
	const LIVE    = 'live';
	const STAGING = 'staging';

	const LABELS = [
		self::LIVE    => 'Live',
		self::STAGING => 'Staging',
	];

	/**
	 * Options that belong to one server. The plain name is routed to "{name}_{env}".
	 */
	const PER_ENV = [
		'regolith_api_url',
		'regolith_api_key',
		'regolith_store_id',
		'regolith_onboarding_status',
		'regolith_setup_complete',
	];

	const OPTION = 'regolith_environment';

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
		// Before the filters exist, so it can still read the old single-server options.
		$this->maybe_migrate();

		foreach (self::PER_ENV as $name) {
			add_filter('pre_option_' . $name, [$this, 'route_read'], 10, 3);
			add_filter('pre_update_option_' . $name, [$this, 'route_write'], 10, 3);
		}
		add_action('delete_option', [$this, 'route_delete']);

		add_action('wp_ajax_regolith_switch_env', [$this, 'ajax_switch']);
	}

	// MARK: Routing

	/**
	 * get_option('regolith_x') → get_option('regolith_x_{env}').
	 * Returning '' (not false) short-circuits, so the old shared value never leaks through.
	 */
	public function route_read($pre, $name, $default = false)
	{
		$value = get_option($name . '_' . $this->active(), null);
		return null === $value ? ('' !== $default && false !== $default ? $default : '') : $value;
	}

	/**
	 * update_option('regolith_x', v) → update_option('regolith_x_{env}', v).
	 * Returning the old value tells WordPress "nothing changed" so the plain option is never written.
	 */
	public function route_write($value, $old_value, $name)
	{
		update_option($name . '_' . $this->active(), $value, false);
		return $old_value;
	}

	public function route_delete($name)
	{
		if (in_array($name, self::PER_ENV, true)) {
			delete_option($name . '_' . $this->active());
		}
	}

	// MARK: State

	public function url($env)
	{
		return untrailingslashit((string) get_option('regolith_api_url_' . $env, ''));
	}

	/**
	 * Servers that have an address, in display order.
	 *
	 * @return string[]
	 */
	public function available()
	{
		return array_values(array_filter([self::STAGING, self::LIVE], fn ($env) => '' !== $this->url($env)));
	}

	/**
	 * The server everything talks to. Falls back to whichever one has an address, so a store with
	 * only one server configured is never pointed at an empty one.
	 */
	public function active()
	{
		$chosen    = get_option(self::OPTION, self::LIVE);
		$available = $this->available();

		if (in_array($chosen, $available, true) || ! $available) {
			return isset(self::LABELS[$chosen]) ? $chosen : self::LIVE;
		}
		return $available[0];
	}

	/**
	 * The switch can only move when both servers have an address.
	 */
	public function is_locked()
	{
		return count($this->available()) < 2;
	}

	/**
	 * Why the switch is locked, for its tooltip.
	 */
	public function lock_reason()
	{
		$available = $this->available();
		if (! $available) {
			return 'No Regolith server address is set yet. Add one in Settings → Connection.';
		}
		$only  = self::LABELS[$available[0]];
		$other = self::LABELS[self::LIVE === $available[0] ? self::STAGING : self::LIVE];
		return sprintf('Only the %s server is set up. Add a %s server address in Settings → Connection to switch.', $only, $other);
	}

	/**
	 * Switch servers.
	 *
	 * @return true|WP_Error
	 */
	public function switch_to($env)
	{
		if (! isset(self::LABELS[$env])) {
			return new WP_Error('regolith_env_unknown', 'Unknown server.');
		}
		if ('' === $this->url($env)) {
			return new WP_Error('regolith_env_missing', sprintf('The %s server address isn\'t set.', self::LABELS[$env]));
		}
		if ($env === $this->active()) {
			return true;
		}
		// A run belongs to the server it started on: its batches can't move to another one.
		if (class_exists('Reg_Sync_DB') && Reg_Sync_DB::I()->get_active_runs()) {
			return new WP_Error('regolith_env_busy', 'A sync is running. Wait for it to finish (or abandon it in Settings → Advanced), then switch.');
		}

		update_option(self::OPTION, $env, false);

		// Server-specific leftovers: a pause reason from the other server, its snapshot job, and
		// the "already sent" flags (the new server needs categories + product list again).
		delete_option('regolith_sync_blocked');
		delete_option('regolith_pcm_state');
		update_option('regolith_categories_dirty', 1, false);
		update_option('regolith_memberships_dirty', 1, false);

		return true;
	}

	// MARK: Migration

	/**
	 * One-time move from the single-server options (≤ 0.1.0). The old address is filed as
	 * Staging when it looks like one (sandbox / staging / test / dev in the host), otherwise Live.
	 */
	private function maybe_migrate()
	{
		if (get_option('regolith_env_migrated')) {
			return;
		}
		update_option('regolith_env_migrated', 1, false);

		$old_url = (string) get_option('regolith_api_url', '');
		if ('' === $old_url) {
			return;
		}

		$host = strtolower((string) wp_parse_url($old_url, PHP_URL_HOST));
		$env  = preg_match('/(sandbox|staging|stage|test|dev)/', $host) ? self::STAGING : self::LIVE;

		foreach (self::PER_ENV as $name) {
			$value = get_option($name, null);
			if (null !== $value && null === get_option($name . '_' . $env, null)) {
				update_option($name . '_' . $env, $value, false);
			}
		}
		update_option(self::OPTION, $env, false);
	}

	// MARK: Server Fields

	/**
	 * Address + API key for each server, plus "use this one now". Shared by the wizard's Connect
	 * step and Settings → Connection. Keys are never printed back, only their last 4 characters.
	 * Fill either or both.
	 */
	public function render_server_fields()
	{
		$active = $this->active();
		?>
		<div class="reg-servers">
			<?php foreach ([self::LIVE, self::STAGING] as $env) :
				$url = $this->url($env);
				$key = (string) get_option('regolith_api_key_' . $env, '');
				?>
				<fieldset class="reg-server <?php echo $env === $active && '' !== $url ? 'is-active' : ''; ?>">
					<legend><?php echo esc_html(self::LABELS[$env]); ?> server
						<?php if ($env === $active && '' !== $url) : ?><?php echo Reg_UI::pill('In use', 'ok'); ?><?php endif; ?></legend>

					<label class="reg-label" for="reg-url-<?php echo esc_attr($env); ?>">Server address</label>
					<input class="reg-input" type="url" id="reg-url-<?php echo esc_attr($env); ?>" name="api_url_<?php echo esc_attr($env); ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://…">

					<label class="reg-label" for="reg-key-<?php echo esc_attr($env); ?>">API key</label>
					<div class="reg-input-group">
						<input class="reg-input" type="password" id="reg-key-<?php echo esc_attr($env); ?>" name="api_key_<?php echo esc_attr($env); ?>" autocomplete="new-password"
							placeholder="<?php echo $key ? esc_attr('Saved, ends with …' . substr($key, -4)) : 'rg_…'; ?>" value="">
						<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-toggle-secret="#reg-key-<?php echo esc_attr($env); ?>">Show</button>
					</div>

					<label class="reg-radio-inline"><input type="radio" name="env" value="<?php echo esc_attr($env); ?>" <?php checked($env, $active); ?>> Use this server now</label>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<p class="reg-muted">Fill in one server or both. Each server has its own API key (create it in that server's Regolith dashboard). Leave a key empty to keep the saved one. With both set, switch between them from the header.</p>
		<?php
	}

	/**
	 * Save the posted server fields. Doesn't test anything, the caller does.
	 *
	 * @return true|WP_Error
	 */
	public function save_server_fields(array $post)
	{
		foreach ([self::LIVE, self::STAGING] as $env) {
			$url = trim((string) ($post['api_url_' . $env] ?? ''));
			$key = trim(sanitize_text_field((string) ($post['api_key_' . $env] ?? '')));

			if ('' !== $url && ! wp_http_validate_url($url)) {
				return new WP_Error('regolith_bad_url', sprintf('The %s server address doesn\'t look like a web address.', self::LABELS[$env]));
			}
			update_option('regolith_api_url_' . $env, '' === $url ? '' : untrailingslashit(esc_url_raw($url)), false);
			if ('' !== $key) {
				update_option('regolith_api_key_' . $env, $key, false);
			}
		}

		if (! $this->available()) {
			return new WP_Error('regolith_no_server', 'Enter at least one Regolith server address (it came with your API key).');
		}

		$wanted = sanitize_key((string) ($post['env'] ?? ''));
		if ('' !== $wanted && '' !== $this->url($wanted)) {
			$switched = $this->switch_to($wanted);
			if (is_wp_error($switched)) {
				return $switched;
			}
		}

		if (! get_option('regolith_api_key')) {
			return new WP_Error('regolith_no_key', sprintf('Paste the API key for the %s server.', self::LABELS[$this->active()]));
		}

		return true;
	}

	// MARK: Switch UI

	/**
	 * The Live / Staging switch for the plugin header (and the wizard's top bar).
	 */
	public function render_switch()
	{
		$active = $this->active();
		$locked = $this->is_locked();
		$tip    = $locked ? $this->lock_reason() : 'Choose which Regolith server this store talks to.';
		?>
		<div class="reg-env <?php echo $locked ? 'is-locked' : ''; ?>" title="<?php echo esc_attr($tip); ?>" data-reg-env>
			<span class="reg-env__label">Server</span>
			<div class="reg-env__switch" role="radiogroup" aria-label="Regolith server">
				<?php foreach ([self::STAGING, self::LIVE] as $env) : ?>
					<button type="button"
						class="reg-env__option reg-env__option--<?php echo esc_attr($env); ?> <?php echo $env === $active ? 'is-active' : ''; ?>"
						role="radio"
						aria-checked="<?php echo $env === $active ? 'true' : 'false'; ?>"
						data-reg-env-target="<?php echo esc_attr($env); ?>"
						<?php disabled($locked || '' === $this->url($env)); ?>><?php echo esc_html(self::LABELS[$env]); ?></button>
				<?php endforeach; ?>
			</div>
			<?php if ($locked) : ?>
				<span class="reg-env__lock" aria-hidden="true"></span>
			<?php endif; ?>
		</div>
		<?php
	}

	public function ajax_switch()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can('manage_options')) {
			wp_send_json_error('Permission denied.', 403);
		}

		$result = $this->switch_to(sanitize_key(wp_unslash($_POST['env'] ?? '')));
		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		wp_send_json_success(['message' => 'Switched to ' . self::LABELS[$this->active()] . '.']);
	}
}
