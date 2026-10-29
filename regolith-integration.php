<?php

/**
 * Plugin Name:       Regolith Integration
 * Plugin URI:        https://regolithapp.com/
 * Description:       Lists your WooCommerce catalog on Regolith and keeps it in sync automatically.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Abdullatif Al-Mayhob
 * Author URI:        https://mayhob.com/en/
 * Text Domain:       regolith-integration
 * Update URI:        https://github.com/Abdoo-mayhob/regolith-integration
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}

// Keep in sync with "Version:" above. The release workflow refuses to release when they differ
// or aren't X.Y.Z. Three parts (1.2.3): PHP's version_compare thinks "0.1" < "0.1.0".
define('REGOLITH_PLUGIN_VERSION', '0.1.0');
define('REGOLITH_PLUGIN_FILE', __FILE__);

// MARK: Updates
// Outside the WooCommerce check on purpose: the plugin must still be able to update itself
// while WooCommerce is deactivated. Only boots in admin / cron / CLI requests (see class).
require_once plugin_dir_path(__FILE__) . 'class-updater.php';
Reg_Updater::I();

add_action('plugins_loaded', function () {
	if (!class_exists('WooCommerce')) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="error"><p>Regolith Integration requires WooCommerce to be active.</p></div>';
			}
		);
		return;
	}


	require_once plugin_dir_path(__FILE__) . 'class-sync-db.php';
	require_once plugin_dir_path(__FILE__) . 'class-product-serializer.php';
	require_once plugin_dir_path(__FILE__) . 'class-api.php';
	require_once plugin_dir_path(__FILE__) . 'class-onboarding.php';
	require_once plugin_dir_path(__FILE__) . 'class-sync-service.php';
	require_once plugin_dir_path(__FILE__) . 'class-sync-queue.php';
	// Admin UI (see class-ui.php for the screen map).
	require_once plugin_dir_path(__FILE__) . 'class-messages.php';
	require_once plugin_dir_path(__FILE__) . 'class-ui.php';
	require_once plugin_dir_path(__FILE__) . 'class-wizard.php';
	require_once plugin_dir_path(__FILE__) . 'class-dashboard.php';
	require_once plugin_dir_path(__FILE__) . 'class-admin.php';
	require_once plugin_dir_path(__FILE__) . 'class-sync-admin.php';
	require_once plugin_dir_path(__FILE__) . 'class-cat-mapper.php';
	require_once plugin_dir_path(__FILE__) . 'class-field-mapper.php';

	Reg_Sync_DB::I();
	Reg_Product_Serializer::I();
	Reg_API::I();
	Reg_Onboarding::I();
	Reg_Sync_Service::I();
	Reg_Sync_Queue::I();
	Reg_UI::I();
	Reg_Wizard::I();
	Reg_Dashboard::I();
	Reg_Admin::I();
	Reg_Sync_Admin::I();
	Reg_Category_Mapper::I();
	Reg_Field_Mapper::I();

	// Hard Safeguard to avoid leaking data to production: the logger writes full request /
	// response bodies to disk, so it only ever loads on dev hosts (see regolith_is_dev()).
	if (regolith_is_dev()) {
		require_once plugin_dir_path(__FILE__) . 'class-logger.php';
		Reg_Logger::I();
	}
});

// Plugin Activation Hook
register_activation_hook(__FILE__, function () {
	require_once plugin_dir_path(__FILE__) . 'class-sync-db.php';
	Reg_Sync_DB::I()->create_tables();

	// Action Scheduler isn't ready this early (its store initialises on `init`), so we just
	// clear the throttle and let Reg_Sync_Queue::ensure_schedule() add the tick on the next
	// admin request.
	delete_transient('regolith_schedule_checked');

	// Take the admin straight into the setup wizard on the next page load (Reg_UI).
	set_transient('regolith_activation_redirect', 1, 60);
});

// Plugin Deactivation Hook
register_deactivation_hook(__FILE__, function () {
	// Legacy WP-Cron worker from the first engine.
	wp_clear_scheduled_hook('regolith_sync_queue_worker');

	if (function_exists('as_unschedule_all_actions')) {
		as_unschedule_all_actions('regolith_sync_tick', [], 'regolith');
		as_unschedule_all_actions('regolith_sync_work', [], 'regolith');
		as_unschedule_all_actions('regolith_onboarding_refresh', [], 'regolith');
	}
	delete_transient('regolith_schedule_checked');
});


// --------------------------------------------------------------------------------------
// MARK: Environment

/**
 * Is this a development site?
 *
 * Decides two things: whether the HTTP logger runs (it writes full payloads to disk) and
 * whether product images are swapped for a public placeholder (a .test host can't be
 * reached by Regolith's image importer).
 *
 * Define REGOLITH_DEV_MODE in wp-config.php to force it either way. Otherwise a host ending
 * in .test / .local / .localhost (or localhost itself) counts as dev.
 *
 * @return bool
 */
if (!function_exists('regolith_is_dev')) {
	function regolith_is_dev()
	{
		if (defined('REGOLITH_DEV_MODE')) {
			return (bool) REGOLITH_DEV_MODE;
		}

		$host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

		return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
			|| (bool) preg_match('/\.(test|local|localhost)$/', $host);
	}
}

