<?php

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Logger
{
	private $log_file;

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
		$this->log_file = $this->prepare_log_dir() . 'mid.log';

		// Use the debug hook to capture the full request/response cycle
		add_action('http_api_debug', [$this, 'log_http_cycle'], 10, 5);

		// Submenu hooks for displaying log telemetry
		add_action('admin_menu', [$this, 'add_logger_submenu']);
	}

	/**
	 * The log lives in wp-content/uploads/regolith-logs/, not in the plugin folder: anything
	 * inside wp-content/plugins is served as-is by the web server, and this file holds full
	 * request/response bodies. The folder gets a deny-all .htaccess (Apache) and an empty
	 * index.php. Nginx ignores .htaccess, so on Nginx boxes keep the logger off (it only loads
	 * on dev hosts anyway).
	 *
	 * @return string Directory path with trailing slash.
	 */
	private function prepare_log_dir()
	{
		$uploads = wp_upload_dir(null, false);
		$dir     = trailingslashit($uploads['basedir']) . 'regolith-logs/';

		if (! is_dir($dir)) {
			wp_mkdir_p($dir);
		}
		if (! file_exists($dir . '.htaccess')) {
			file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");
		}
		if (! file_exists($dir . 'index.php')) {
			file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n");
		}

		// One-time move of the old log that used to sit in the plugin folder.
		$legacy = plugin_dir_path(__FILE__) . 'mid.log';
		if (file_exists($legacy) && ! file_exists($dir . 'mid.log')) {
			@rename($legacy, $dir . 'mid.log');
		}

		return $dir;
	}

	/**
	 * Registers the Logger viewer panel under the core parent wrapper layout.
	 */
	public function add_logger_submenu()
	{
		// Hidden page (empty parent), linked from Settings → Advanced on dev sites.
		add_submenu_page(
			'',
			'API Logs',
			'API Logs',
			'manage_options',
			'regolith-logs',
			[$this, 'render_logger_page']
		);
	}

	/**
	 * Handles administrative actions and displays raw payload log dumps safely.
	 */
	public function render_logger_page()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// Handle Log Toggling
		if (isset($_POST['reg_save_logger_settings']) && check_admin_referer('reg_logger_settings_nonce')) {
			$status = isset($_POST['reg_logger_enabled']) ? '1' : '0';
			update_option('regolith_logger_enabled', $status);
			echo '<div class="updated"><p>Logger configuration updated.</p></div>';
		}

		// Handle Clear Log File Action
		if (isset($_POST['reg_clear_log']) && check_admin_referer('reg_clear_log_nonce')) {
			if (file_exists($this->log_file)) {
				file_put_contents($this->log_file, '');
			}
			echo '<div class="updated"><p>Log file cleared successfully.</p></div>';
		}

		// Gather environmental details
		$is_enabled = get_option('regolith_logger_enabled', '1') === '1';
		$file_size  = file_exists($this->log_file) ? size_format(filesize($this->log_file)) : '0 B';
		$log_data   = file_exists($this->log_file) ? esc_html(file_get_contents($this->log_file)) : '';
?>
		<div class="wrap">
			<h1>Regolith API Logs</h1>

			<div class="card" style="max-width: 100%; margin-top: 20px;">
				<h2>Logger Settings</h2>
				<form method="post" action="">
					<?php wp_nonce_field('reg_logger_settings_nonce'); ?>
					<table class="form-table">
						<tr>
							<th scope="row">Logging Engine Status</th>
							<td>
								<label for="reg_logger_enabled">
									<input type="checkbox" id="reg_logger_enabled" name="reg_logger_enabled" value="1" <?php checked($is_enabled); ?>>
									Enable
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Active Log File Size</th>
							<td><code><?php echo esc_html($file_size); ?></code></td>
						</tr>
					</table>
					<?php submit_button('Save Settings', 'primary', 'reg_save_logger_settings', false); ?>
				</form>

				<?php if (!empty($log_data)) : ?>
					<form method="post" action="" style="display:inline-block; margin-left: 10px;">
						<?php wp_nonce_field('reg_clear_log_nonce'); ?>
						<?php submit_button('Clear Log Contents', 'delete', 'reg_clear_log', false, ['onclick' => "return confirm('Are you sure you want to completely erase the logs?');"]); ?>
					</form>
				<?php endif; ?>
			</div>

			<hr />

			<h2>Console Trace Output</h2>
			<?php if (!empty($log_data)) : ?>
				<pre style="background: #272822; color: #f8f8f2; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 600px; font-family: Consolas, Monaco, monospace;"><?php echo $log_data; ?></pre>
			<?php else : ?>
				<div class="notice notice-info inline">
					<p>Log tracking trace console is currently empty.</p>
				</div>
			<?php endif; ?>
		</div>
<?php
	}

	private function write_log($prefix, $data)
	{
		// Intercept execution pathways if the logging engine has been disabled
		if (get_option('regolith_logger_enabled', '1') !== '1') {
			return;
		}

		// Full Sync batches are ~100KB each, so cap the file instead of filling the disk.
		// Over 5 MB the current log becomes mid.log.1 (overwriting the previous one).
		if (file_exists($this->log_file) && filesize($this->log_file) > 5 * MB_IN_BYTES) {
			@rename($this->log_file, $this->log_file . '.1');
		}

		$time = current_time('mysql');
		// JSON_PRETTY_PRINT ensures the output is readable
		$message = is_array($data) ? wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $data;
		$entry = "[$time] [$prefix]" . PHP_EOL . $message . PHP_EOL . str_repeat('-', 40) . PHP_EOL;
		file_put_contents($this->log_file, $entry, FILE_APPEND);
	}

	/**
	 * Scrubs headers array to shield production keys from raw data exposures.
	 */
	private function mask_auth_headers($headers)
	{
		if (!is_array($headers)) {
			return $headers;
		}

		foreach ($headers as $key => $value) {
			if (strtolower($key) === 'authorization') {
				$headers[$key] = 'RegolithKey ****** [MASKED]';
			}
		}
		return $headers;
	}

	public function log_http_cycle($response, $context, $class, $parsed_args, $url)
	{
		$regolith_url = get_option('regolith_api_url', '');

		// Only log if the request is related to Regolith
		if (empty($regolith_url) || strpos($url, $regolith_url) === false) {
			return;
		}

		$method = $parsed_args['method'] ?? 'GET';

		// Mask authentication values in headers before tracking the log array payload
		$headers = $parsed_args['headers'] ?? [];
		$masked_headers = $this->mask_auth_headers($headers);

		// 1. Prepare and log Outgoing Request
		$req_data = [
			'url'     => $url,
			'headers' => $masked_headers,
		];

		if (!empty($parsed_args['body'])) {
			$raw_body = $parsed_args['body'];
			$decoded = is_string($raw_body) ? json_decode($raw_body, true) : $raw_body;
			$req_data['body'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw_body;
		}

		$this->write_log("OUTGOING REQ [$method]", $req_data);

		// 2. Prepare and log Incoming Response
		if (is_wp_error($response)) {
			$this->write_log('OUTGOING ERR', $response->get_error_message());
		} else {
			$res_code = wp_remote_retrieve_response_code($response);
			$res_body = wp_remote_retrieve_body($response);

			$res_data = [
				'status_code' => $res_code,
				'body'        => json_decode($res_body, true) ?: $res_body
			];

			$this->write_log("INCOMING RES [$res_code]", $res_data);
		}
	}
}
