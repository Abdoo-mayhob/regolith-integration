<?php

/**
 * Regolith Messages
 *
 * Turns Regolith error / warning codes into something a store owner can act on.
 * The rest of the plugin stores and branches on CODES; anything a human reads goes through
 * here. Codes stay visible in a "details for support" corner, never as the headline.
 *
 * Each entry:
 *   title   short headline, used for grouping on the "Needs attention" page
 *   help    one or two sentences: what it means, what to do
 *   action  where the fix is: 'product' (edit the product), 'categories', 'details',
 *           'connection', 'support' (nothing the store owner can fix), 'retry' (just wait)
 *   level   'error' (needs a human) | 'warning' (product not listed, maybe fine) | 'info'
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Messages
{
	/**
	 * Where people go to get an API key / talk to Regolith.
	 */
	const DASHBOARD_URL = 'https://dashboard.regolithapp.com/';
	const SUPPORT_URL   = 'https://calendly.com/regolithapp-support/30min';

	// MARK: Catalog

	public static function all()
	{
		return [
			// Row-level: product data
			'REQUIRED_ITEM_FIELD_MISSING' => [
				'title'  => 'Missing or invalid product information',
				'help'   => 'Regolith needs a name (under 200 characters), a SKU under 100 characters and a price that isn\'t negative. Open the product and check those fields.',
				'action' => 'product',
				'level'  => 'error',
			],
			'VARIATION_PARENT_MISMATCH' => [
				'title'  => 'Variation linked to the wrong product',
				'help'   => 'This variation doesn\'t match its parent product. Re-saving the parent product usually fixes it. If not, contact support.',
				'action' => 'product',
				'level'  => 'error',
			],
			'PRICE_MISSING' => [
				'title'  => 'No price',
				'help'   => 'Products without a price (or with a price of 0) aren\'t listed. Add a regular price to list it.',
				'action' => 'product',
				'level'  => 'warning',
			],
			'CATEGORY_SELECTION_REQUIRED' => [
				'title'  => 'Category choice needed',
				'help'   => 'This product is in several categories that point to different Regolith categories. Pick the one Regolith should use.',
				'action' => 'categories',
				'level'  => 'error',
			],
			'CATEGORY_DECISION_REQUIRED' => [
				'title'  => 'New category needs a decision',
				'help'   => 'A WooCommerce category hasn\'t been matched to a Regolith category yet. Products in it stay unlisted until you decide.',
				'action' => 'categories',
				'level'  => 'error',
			],
			'INTERNAL_CATEGORY_INACTIVE' => [
				'title'  => 'Regolith category no longer available',
				'help'   => 'The Regolith category this product was matched to has been retired. Choose a new one in your category settings.',
				'action' => 'categories',
				'level'  => 'error',
			],
			'VENDOR_CATEGORY_NOT_IN_STORE' => [
				'title'  => 'Category out of date',
				'help'   => 'Your categories changed since Regolith last heard about them. They\'re re-sent automatically within a few minutes.',
				'action' => 'retry',
				'level'  => 'warning',
			],
			'IMAGE_IMPORT_FAILED' => [
				'title'  => 'Image couldn\'t be imported',
				'help'   => 'Regolith couldn\'t download this product\'s image. Make sure the product has an image and that your site is reachable from the internet. We retry automatically.',
				'action' => 'product',
				'level'  => 'error',
			],
			'IMAGE_UNSAFE' => [
				'title'  => 'Image not accepted',
				'help'   => 'Images must be a public HTTPS link to a JPEG, PNG or WebP file. Upload the image again or replace it.',
				'action' => 'product',
				'level'  => 'error',
			],
			'IMAGE_LIMIT_EXCEEDED' => [
				'title'  => 'Image too large',
				'help'   => 'Images must be under 10 MB and under 40 megapixels. Upload a smaller version.',
				'action' => 'product',
				'level'  => 'error',
			],
			'CURRENCY_CONVERSION_FAILED' => [
				'title'  => 'Price couldn\'t be converted',
				'help'   => 'Regolith couldn\'t convert this price to your account currency. This is on Regolith\'s side, we retry automatically. Contact support if it stays.',
				'action' => 'support',
				'level'  => 'error',
			],
			'ROW_PROCESSING_FAILED' => [
				'title'  => 'Temporary problem on Regolith\'s side',
				'help'   => 'Regolith hit an unexpected error with this product. We retry automatically, no action needed unless it stays here.',
				'action' => 'retry',
				'level'  => 'warning',
			],
			'EXTERNAL_UPDATE_CONFLICT' => [
				'title'  => 'Update conflict',
				'help'   => 'Two different versions of this product arrived with the same timestamp. Saving the product again sends a fresh version.',
				'action' => 'product',
				'level'  => 'error',
			],
			'ITEM_DELETED' => [
				'title'  => 'Removed on Regolith',
				'help'   => 'This item was deleted on Regolith, so updates from your store aren\'t applied any more. Contact support if it should come back.',
				'action' => 'support',
				'level'  => 'warning',
			],
			'SERIALIZE_FAILED' => [
				'title'  => 'We couldn\'t read this product',
				'help'   => 'The plugin hit an error reading this product (often caused by another plugin). Send the details below to support.',
				'action' => 'support',
				'level'  => 'error',
			],
			'ROW_REJECTED' => [
				'title'  => 'Rejected by Regolith',
				'help'   => 'Regolith refused this product without saying why. Contact support with the details below.',
				'action' => 'support',
				'level'  => 'error',
			],

			// Store / connection
			'STORE_ALREADY_LINKED' => [
				'title'  => 'Account already connected to another store',
				'help'   => 'This Regolith account is linked to a different WooCommerce store. Ask Regolith support to unlink it first.',
				'action' => 'support',
				'level'  => 'error',
			],
			'STORE_SITE_URL_IMMUTABLE' => [
				'title'  => 'Store address changed',
				'help'   => 'This store was registered with a different web address, and Regolith doesn\'t allow changing it. Contact Regolith support.',
				'action' => 'support',
				'level'  => 'error',
			],
			'STORE_DISCONNECTED' => [
				'title'  => 'Store disconnected by Regolith',
				'help'   => 'A Regolith admin disconnected this store. Reconnect it in Settings → Connection, then run setup again.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'STORE_NOT_FOUND' => [
				'title'  => 'Store not found on Regolith',
				'help'   => 'Regolith doesn\'t recognise this store any more. Reconnect it in Settings → Connection.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'STORE_OWNERSHIP_INVALID' => [
				'title'  => 'API key belongs to another account',
				'help'   => 'The API key and this store belong to different Regolith accounts (or environments). Use the key from the right account.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'VENDOR_INTEGRATION_API_KEY_REQUIRED' => [
				'title'  => 'API key not accepted',
				'help'   => 'The API key is missing, revoked, or not an integration key. Create a new key in your Regolith dashboard and paste it in Settings → Connection.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'regolith_auth_invalid' => [
				'title'  => 'API key not accepted',
				'help'   => 'Regolith refused this API key. Copy it again from your Regolith dashboard (Integration API keys) and make sure nothing is missing.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'VENDOR_COUNTRY_REQUIRED' => [
				'title'  => 'Your Regolith account isn\'t fully set up',
				'help'   => 'Your Regolith vendor account has no country / currency yet. Regolith support needs to finish it before products can be listed.',
				'action' => 'support',
				'level'  => 'error',
			],
			'regolith_missing_api_key' => [
				'title'  => 'Not connected',
				'help'   => 'Add your Regolith API key in Settings → Connection.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'regolith_missing_api_url' => [
				'title'  => 'Regolith server address missing',
				'help'   => 'The Regolith server address isn\'t set. It\'s provided by Regolith with your API key.',
				'action' => 'connection',
				'level'  => 'error',
			],
			'http_request_failed' => [
				'title'  => 'Couldn\'t reach Regolith',
				'help'   => 'Your site couldn\'t connect to Regolith (network problem or Regolith is briefly unavailable). We retry automatically.',
				'action' => 'retry',
				'level'  => 'warning',
			],

			// Onboarding
			'MEMBERSHIP_SNAPSHOT_REQUIRED' => [
				'title'  => 'Product list not sent yet',
				'help'   => 'Regolith needs your product list before setup can finish. Go back to the Categories step and save again.',
				'action' => 'categories',
				'level'  => 'error',
			],
			'CATEGORY_SNAPSHOT_EMPTY' => [
				'title'  => 'No categories found',
				'help'   => 'Your store has no product categories. Create at least one in Products → Categories.',
				'action' => 'categories',
				'level'  => 'error',
			],
		];
	}

	/**
	 * Message for a code, with a generic fallback so nothing ever shows up blank.
	 *
	 * @param  string $code
	 * @param  string $raw_message Server message, used as help text for unknown codes.
	 * @return array{title: string, help: string, action: string, level: string, code: string}
	 */
	public static function get($code, $raw_message = '')
	{
		$all  = self::all();
		$code = (string) $code;

		if (isset($all[$code])) {
			return $all[$code] + ['code' => $code];
		}

		return [
			'title'  => 'Something went wrong',
			'help'   => $raw_message ? $raw_message : 'Regolith returned an error we don\'t have a description for yet. Contact support with the details below.',
			'action' => 'support',
			'level'  => 'error',
			'code'   => $code,
		];
	}

	/**
	 * One-liner for a WP_Error from Reg_API: friendly headline + help.
	 */
	public static function from_error(WP_Error $error)
	{
		$info = Reg_API::I()->extract_error_info($error);

		// 401/403 without a code are bad keys too.
		$code = $info['code'];
		if (in_array($info['http_code'], [401, 403], true) && ! isset(self::all()[$code])) {
			$code = 'regolith_auth_invalid';
		}

		return self::get($code, $info['message']) + ['raw' => $info['message'], 'http_code' => $info['http_code']];
	}

	/**
	 * Where the "fix it" button of a message goes.
	 *
	 * @return array{url: string, label: string}|null
	 */
	public static function action_link(array $message, $product_id = 0)
	{
		switch ($message['action']) {
			case 'product':
				return $product_id ? ['url' => get_edit_post_link($product_id, 'raw'), 'label' => 'Edit product'] : null;
			case 'categories':
				return ['url' => Reg_UI::url('settings', ['tab' => 'categories']), 'label' => 'Open category settings'];
			case 'details':
				return ['url' => Reg_UI::url('settings', ['tab' => 'details']), 'label' => 'Open product details'];
			case 'connection':
				return ['url' => Reg_UI::url('settings', ['tab' => 'connection']), 'label' => 'Open connection settings'];
			case 'support':
				return ['url' => self::SUPPORT_URL, 'label' => 'Contact Regolith support'];
			default:
				return null;
		}
	}
}
