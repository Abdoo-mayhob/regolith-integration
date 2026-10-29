<?php

/**
 * Plugin Updates (private GitHub releases)
 *
 * Wraps Plugin Update Checker 5.7 (plugin-update-checker-5.7/, unmodified vendor code) so the
 * plugin updates itself from GitHub releases of the public repo, the normal WordPress way
 * ("Update now" on the Plugins screen, optional auto-updates).
 *
 * How a release reaches a store:
 *   1. A version bump pushed to main triggers .github/workflows/release.yml, which tags it,
 *      builds regolith-integration.zip (git archive, dev files left out) and publishes a release.
 *   2. Every 12 h (or "Check now") this class asks GitHub for the LATEST RELEASE, compares
 *      its version with ours, and WordPress downloads the attached zip.
 *
 * Decisions, and why:
 * - Releases only. PUC would otherwise fall back to tags, then to the `main` branch itself,
 *   so any push with a bumped version would ship before the workflow checked it.
 * - The attached zip is REQUIRED: it is the one built and linted by the workflow.
 * - The repository is public, no token needed. Optional REGOLITH_UPDATE_TOKEN in wp-config.php
 *   (or the `regolith_update_token` filter) only helps on shared hosting that hits GitHub's
 *   60-requests-an-hour anonymous limit.
 * - A git working copy (this folder has .git) can CHECK but never INSTALL: an update would
 *   overwrite the developer's uncommitted work. The download is refused with a clear message.
 * - Loaded only in admin, cron and WP-CLI requests. Storefront page views never load PUC.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api as PucVcsApi;

class Reg_Updater
{
	const REPOSITORY = 'https://github.com/Abdoo-mayhob/regolith-integration/';
	const SLUG       = 'regolith-integration';

	/**
	 * Name of the zip attached to each GitHub release (built by .github/workflows/release.yml).
	 */
	const ASSET_REGEX = '/^regolith-integration\.zip$/i';

	/**
	 * Hours between background checks.
	 */
	const CHECK_PERIOD = 12;

	const ERROR_OPTION = 'regolith_update_last_error';

	/**
	 * @var \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker|null
	 */
	private $checker = null;

	/**
	 * An API error was recorded during this request (so a check that follows doesn't clear it).
	 *
	 * @var bool
	 */
	private $had_error = false;

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
		// Updates are checked and installed from wp-admin, WP-Cron and WP-CLI only.
		if (! (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI))) {
			return;
		}

		$this->boot();

		add_action('wp_ajax_regolith_check_updates', [$this, 'ajax_check']);
	}

	// MARK: Setup

	private function boot()
	{
		$library = plugin_dir_path(REGOLITH_PLUGIN_FILE) . 'plugin-update-checker-5.7/plugin-update-checker.php';
		if (! file_exists($library)) {
			return;
		}
		require_once $library;

		$this->checker = PucFactory::buildUpdateChecker(self::REPOSITORY, REGOLITH_PLUGIN_FILE, self::SLUG, self::CHECK_PERIOD);
		$this->checker->setBranch('main');

		[$token] = $this->token();
		if ('' !== $token) {
			$this->checker->setAuthentication($token);
		}

		$this->checker->getVcsApi()->enableReleaseAssets(self::ASSET_REGEX, PucVcsApi::REQUIRE_RELEASE_ASSETS);

		// Releases only: drop the "latest tag" and "branch" fallbacks.
		add_filter($this->checker->getUniqueName('vcs_update_detection_strategies'), static function ($strategies) {
			return array_intersect_key($strategies, [PucVcsApi::STRATEGY_LATEST_RELEASE => true]);
		});

		// Remember the last GitHub error for the Advanced tab (PUC only keeps it per request).
		add_action('puc_api_error', [$this, 'record_error'], 10, 4);
		add_filter($this->checker->getUniqueName('request_update_result'), [$this, 'clear_error_after_check'], 10, 1);

		// Never overwrite a git working copy.
		add_filter('upgrader_pre_download', [$this, 'protect_working_copy'], 10, 3);
	}

	/**
	 * The GitHub token and where it came from.
	 *
	 * @return array{0: string, 1: string} [token, 'wp-config' | 'filter' | 'none']
	 */
	public function token()
	{
		if (defined('REGOLITH_UPDATE_TOKEN') && '' !== trim((string) REGOLITH_UPDATE_TOKEN)) {
			return [trim((string) REGOLITH_UPDATE_TOKEN), 'wp-config'];
		}

		/**
		 * Filter the GitHub token used to download plugin updates.
		 *
		 * @param string $token
		 */
		$filtered = trim((string) apply_filters('regolith_update_token', ''));
		if ('' !== $filtered) {
			return [$filtered, 'filter'];
		}

		return ['', 'none'];
	}

	public function is_working_copy()
	{
		return is_dir(plugin_dir_path(REGOLITH_PLUGIN_FILE) . '.git');
	}

	// MARK: Hooks

	/**
	 * @param WP_Error|mixed $error
	 */
	public function record_error($error, $http_response = null, $url = null, $slug = null)
	{
		if (null !== $slug && self::SLUG !== $slug) {
			return; // another plugin's PUC
		}

		$code = is_array($http_response) ? (int) wp_remote_retrieve_response_code($http_response) : 0;
		$this->had_error = true;

		update_option(self::ERROR_OPTION, [
			'http'    => $code,
			'message' => is_wp_error($error) ? $error->get_error_message() : (string) $error,
			'at'      => time(),
		], false);
	}

	public function clear_error_after_check($update)
	{
		if (! $this->had_error) {
			delete_option(self::ERROR_OPTION);
		}
		return $update;
	}

	/**
	 * Refuse to install over a git checkout. Runs for manual AND automatic updates.
	 *
	 * @param bool|WP_Error $reply
	 * @param string        $package Download URL.
	 */
	public function protect_working_copy($reply, $package, $upgrader = null)
	{
		if (false !== $reply || ! $this->is_working_copy()) {
			return $reply;
		}
		if (false === strpos((string) $package, 'Abdoo-mayhob/regolith-integration')) {
			return $reply;
		}

		return new WP_Error(
			'regolith_working_copy',
			'Regolith Integration is a git working copy on this site (it has a .git folder). Updating would overwrite uncommitted work, so it is blocked. Use git to update this copy.'
		);
	}

	// MARK: Status

	/**
	 * Everything the Advanced tab shows, as plain data.
	 */
	public function status()
	{
		[, $source] = $this->token();

		$status = [
			'installed'    => REGOLITH_PLUGIN_VERSION,
			'loaded'       => null !== $this->checker,
			'token_source' => $source,
			'last_check'   => 0,
			'latest'       => null,
			'download'     => false,
			'error'        => get_option(self::ERROR_OPTION, null),
			'working_copy' => $this->is_working_copy(),
		];

		if ($this->checker) {
			$state                = $this->checker->getUpdateState();
			$status['last_check'] = (int) $state->getLastCheck();
			$update               = $this->checker->getUpdate();
			if ($update) {
				$status['latest']   = $update->version;
				$status['download'] = ! empty($update->download_url);
			}
		}

		return $status;
	}

	/**
	 * What a GitHub error means, in words.
	 */
	private function explain_error(array $error)
	{
		switch ((int) $error['http']) {
			case 401:
				return 'GitHub refused the REGOLITH_UPDATE_TOKEN from wp-config.php (expired or revoked). Remove it, it isn\'t needed for a public repository.';
			case 403:
			case 429:
				return 'GitHub rate limit (60 requests an hour per server IP without a token, common on shared hosting). It retries on its own; a REGOLITH_UPDATE_TOKEN in wp-config.php avoids it.';
			case 404:
				return 'GitHub says the repository or release doesn\'t exist. Check the repository is still public at its original address.';
			default:
				return $error['message'] ?: 'Couldn\'t reach GitHub.';
		}
	}

	// MARK: Panel

	/**
	 * Card for Settings → Advanced.
	 */
	public function render_panel()
	{
		$s       = $this->status();
		$sources = [
			'wp-config' => 'set in wp-config.php (REGOLITH_UPDATE_TOKEN)',
			'filter'    => 'provided by the regolith_update_token filter',
			'none'      => 'none (not needed, public repository)',
		];
		?>
		<section class="reg-card" data-reg-updates>
			<div class="reg-card__head">
				<div>
					<h3>Plugin updates</h3>
					<p class="reg-muted">New versions come from GitHub releases and install like any other plugin update (Plugins screen, or automatically if auto-updates are on).</p>
				</div>
				<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-check-updates <?php disabled(! $s['loaded']); ?>>Check now</button>
			</div>

			<?php if ($s['working_copy']) : ?>
				<div class="reg-alert reg-alert--info">This copy is a <strong>git working copy</strong>. Updates are detected but installing them is blocked here, so uncommitted work is never overwritten.</div>
			<?php endif; ?>

			<?php if ($s['error'] && $s['error']['at'] >= $s['last_check'] - 5) : ?>
				<div class="reg-alert reg-alert--error"><strong>Last check failed.</strong> <?php echo esc_html($this->explain_error($s['error'])); ?></div>
			<?php endif; ?>

			<dl class="reg-dl">
				<dt>Installed version</dt><dd><?php echo esc_html($s['installed']); ?></dd>
				<dt>Latest release</dt><dd><?php
					if ($s['latest']) {
						echo esc_html($s['latest']) . ' ' . Reg_UI::pill('Update available', 'info');
					} elseif ($s['last_check']) {
						echo 'You have the latest version';
					} else {
						echo 'Not checked yet';
					}
				?></dd>
				<dt>Last checked</dt><dd><?php echo $s['last_check'] ? esc_html(human_time_diff($s['last_check'], time()) . ' ago') : 'never'; ?></dd>
				<dt>GitHub token</dt><dd><?php echo esc_html($sources[$s['token_source']] ?? $s['token_source']); ?></dd>
				<dt>Source</dt><dd><code>Abdoo-mayhob/regolith-integration</code>, releases with <code>regolith-integration.zip</code> attached</dd>
			</dl>

			<?php if ($s['latest'] && ! $s['working_copy']) : ?>
				<p><a class="reg-btn reg-btn--primary reg-btn--small" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Go to Plugins to update</a></p>
			<?php endif; ?>
			<span class="reg-wizard__status" data-reg-status></span>
		</section>
		<?php
	}

	// MARK: AJAX

	public function ajax_check()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can('update_plugins')) {
			wp_send_json_error('You can\'t update plugins on this site.', 403);
		}
		if (! $this->checker) {
			wp_send_json_error('The update library isn\'t loaded.');
		}

		$this->checker->checkForUpdates();

		ob_start();
		$this->render_panel();
		wp_send_json_success(['html' => ob_get_clean()]);
	}
}
