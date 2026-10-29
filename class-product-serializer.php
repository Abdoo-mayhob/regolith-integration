<?php

/**
 * Regolith Product Serializer
 *
 * Deterministically extracts and formats WooCommerce product and variation data
 * according to the Regolith Inventory Synchronization contract (LATEST reference doc).
 *
 * The one entry point everybody should use is rows_for_product() / rows_for_queue_item().
 * They decide WHICH rows a product turns into (that's product-type policy), the
 * serialize_* methods only decide what a single row looks like.
 *
 * Determinism matters here: the same WooCommerce state must produce the same JSON,
 * otherwise "nothing changed" syncs show up as Updated and replays look like conflicts.
 * So: sorted attribute keys, sorted category IDs, no "now" timestamps for live products.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Product_Serializer
{
	/**
	 * Field limits from the API (REQUIRED_ITEM_FIELD_MISSING when exceeded).
	 */
	const MAX_NAME_LENGTH = 200;
	const MAX_SKU_LENGTH  = 100;

	/**
	 * Image used instead of real product images on dev sites (reg.test is not reachable from
	 * the internet, so Regolith can't import anything from it and every item would stay
	 * inactive). Only ever used when regolith_is_dev() is true.
	 */
	const DEV_PLACEHOLDER_IMAGE = 'https://fastly.picsum.photos/id/0/800/600.jpg?hmac=qRIxZPb6D3nnquI4AQ4xTVwhTvVx1FL3Pdm29Jz61fQ';

	/**
	 * Post statuses that count as "in the catalog". Drafts/private/scheduled products are sent
	 * too (as unpublished, so inactive) to keep their binding alive; trash is left out and gets
	 * reconciled away by the next successful Full run.
	 */
	const CATALOG_STATUSES = ['publish', 'draft', 'pending', 'private', 'future'];

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

	// MARK: Row Selection

	/**
	 * Every inventory row a product turns into.
	 *
	 * | WooCommerce type | Rows                                                            |
	 * |------------------|-----------------------------------------------------------------|
	 * | simple/external  | one                                                             |
	 * | custom types     | one, standard fields                                            |
	 * | variable         | one per variation. The parent is NOT sent: Regolith answers     |
	 * |                  | Skipped (VARIABLE_PARENT_SKIPPED) anyway, and an old parent item |
	 * |                  | gets deactivated by the next Full reconciliation.               |
	 * | grouped          | none. Its children are products of their own and get synced    |
	 * |                  | when the catalog loop reaches them.                             |
	 * | variation        | one (when called with a variation directly)                    |
	 *
	 * @param  WC_Product|int $product_or_id
	 * @return array[] Rows (possibly empty).
	 */
	public function rows_for_product($product_or_id)
	{
		$product = $product_or_id instanceof WC_Product ? $product_or_id : wc_get_product($product_or_id);
		if (! $product instanceof WC_Product) {
			return [];
		}

		if ($product->is_type('variation')) {
			return [$this->serialize_variation($product)];
		}

		if ($product->is_type('variable')) {
			$rows = [];
			foreach ($product->get_children() as $variation_id) {
				$variation = wc_get_product($variation_id);
				if ($variation instanceof WC_Product_Variation) {
					$rows[] = $this->serialize_variation($variation, $product);
				}
			}
			return $rows;
		}

		if ($product->is_type('grouped')) {
			return [];
		}

		return [$this->serialize_product($product)];
	}

	/**
	 * Rows for one delta queue entry. Unlike rows_for_product() this copes with posts that are
	 * gone for good: it builds a deletion row from the queue identity, which is the only place
	 * that still knows a deleted variation's parent.
	 *
	 * @param  int    $product_id   Parent product ID.
	 * @param  int    $variation_id Variation ID or 0.
	 * @param  string $operation    'update' | 'delete'.
	 * @return array[]
	 */
	public function rows_for_queue_item($product_id, $variation_id, $operation)
	{
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		if ($variation_id > 0) {
			$variation = wc_get_product($variation_id);
			if ($variation instanceof WC_Product_Variation) {
				return [$this->serialize_variation($variation, null, 'delete' === $operation)];
			}
			// Variation row is gone from the DB.
			return [$this->deletion_row($product_id, $variation_id)];
		}

		$product = wc_get_product($product_id);
		if (! $product instanceof WC_Product) {
			// Parent post is gone. Its variations were queued separately (before_delete_post
			// runs while they still exist), so only a non-variable product needs a row here.
			// We can't know the old type any more. The ledger can tell us when it was a skipped
			// parent (variable/grouped), those have nothing to deactivate. Anything else gets a
			// deletion row; worst case it's one harmless extra row.
			$state = Reg_Sync_DB::I()->get_product_state($product_id, 0);
			if ($state && 'Skipped' === $state['last_sync_status'] && empty($state['last_item_id'])) {
				return [];
			}
			return [$this->deletion_row($product_id, 0)];
		}

		// The product is a variable/grouped parent: only the variations matter, and those
		// have their own queue rows.
		if ($product->is_type('variable') || $product->is_type('grouped')) {
			return [];
		}

		return [$this->serialize_product($product, 'delete' === $operation)];
	}

	// MARK: Serialize Product

	/**
	 * Serialize a top-level WooCommerce product (simple, external, custom types).
	 *
	 * @param  WC_Product $product
	 * @param  bool       $is_deleted
	 * @return array
	 */
	public function serialize_product(WC_Product $product, $is_deleted = false)
	{
		$product_id  = $product->get_id();
		$post_status = get_post_status($product_id);
		$is_trash    = ($post_status === 'trash' || $is_deleted);

		// External Purchase URL
		$external_purchase_url = null;
		if ($product->is_type('external') && method_exists($product, 'get_product_url')) {
			$url = $product->get_product_url();
			$external_purchase_url = ! empty($url) ? $url : null;
		}

		$pricing = $this->resolve_pricing($product);

		return [
			'externalProductId'       => 'product:' . $product_id,
			'externalVariationId'     => null,
			'parentExternalProductId' => null,
			'productType'             => $product->get_type(),
			'externalUpdatedAt'       => $this->get_iso_updated_at($product),
			'name'                    => $this->clean_name($product->get_name(), $product_id),
			// WooCommerce SKU first, the mapped "SKU / part number" source only when it's empty.
			'sku'                     => $this->clean_sku($product->get_sku() ?: Reg_Field_Mapper::I()->value_for($product, null, 'Sku')),
			'regularPrice'            => $pricing['regularPrice'],
			'salePrice'               => $pricing['salePrice'],
			'saleIsActive'            => $pricing['saleIsActive'],
			'brand'                   => $this->resolve_brand($product),
			'imageUrl'                => $this->filter_image_url($this->resolve_image_url($product), $product),
			'categoryExternalIds'     => $this->get_product_category_ids($product_id),
			'externalPurchaseUrl'     => $external_purchase_url,
			'isPublished'             => ($post_status === 'publish' && ! $is_trash),
			'isPurchasable'           => $product->is_purchasable(),
			'isInStock'               => $product->is_in_stock(),
			'isDeleted'               => $is_trash,
			'attributes'              => $this->as_json_object($this->build_attributes($product)),
		];
	}

	// MARK: Serialize Variation

	/**
	 * Serialize a WooCommerce product variation.
	 *
	 * Identity (LATEST doc, "Plugin Guidance"):
	 * - externalProductId       = the PARENT's id (product:{parent})
	 * - externalVariationId     = the variation's own id
	 * - parentExternalProductId = parent again (must equal externalProductId)
	 *
	 * @param  WC_Product_Variation     $variation
	 * @param  WC_Product_Variable|null $parent_product
	 * @param  bool                     $is_deleted
	 * @return array
	 */
	public function serialize_variation(WC_Product_Variation $variation, $parent_product = null, $is_deleted = false)
	{
		$variation_id = $variation->get_id();
		$parent_id    = $variation->get_parent_id();

		if (! $parent_product && $parent_id) {
			$parent_product = wc_get_product($parent_id);
		}
		$has_parent = $parent_product instanceof WC_Product;

		$post_status = get_post_status($variation_id);
		$is_trash    = ($post_status === 'trash' || $is_deleted);
		if ($has_parent && get_post_status($parent_id) === 'trash') {
			$is_trash = true;
		}

		// Variations inherit the parent's category selection. Sending the parent's IDs (instead
		// of an empty list) keeps the row self-describing, both are allowed.
		$category_ids = $has_parent ? $this->get_product_category_ids($parent_id) : [];

		// Brand: the variation's own value first, then the parent's (handled inside).
		$brand = $this->resolve_brand($variation, $has_parent ? $parent_product : null);

		$pricing = $this->resolve_pricing($variation);

		// SKU: the variation's own ('edit' context, 'view' would already inherit the parent's),
		// then the mapped part-number source, then the parent's SKU.
		$sku = $variation->get_sku('edit');
		if (empty($sku)) {
			$sku = Reg_Field_Mapper::I()->value_for($variation, $has_parent ? $parent_product : null, 'Sku');
		}
		if (empty($sku) && $has_parent) {
			$sku = $parent_product->get_sku();
		}

		$is_published = ($post_status === 'publish' && ! $is_trash);
		if ($has_parent && get_post_status($parent_id) !== 'publish') {
			$is_published = false;
		}

		// WooCommerce keeps a variation's modified date when only the parent changes (new
		// title, category, brand, image...). The payload still changes, so send the later of
		// the two dates, as the LATEST doc asks.
		$updated_at = $this->get_iso_updated_at($variation);
		if ($has_parent) {
			$parent_updated = $this->get_iso_updated_at($parent_product);
			if (strcmp($parent_updated, $updated_at) > 0) {
				$updated_at = $parent_updated;
			}
		}

		return [
			'externalProductId'       => 'product:' . $parent_id,
			'externalVariationId'     => 'variation:' . $variation_id,
			'parentExternalProductId' => 'product:' . $parent_id,
			'productType'             => 'variation',
			'externalUpdatedAt'       => $updated_at,
			'name'                    => $this->clean_name($variation->get_name(), $variation_id),
			'sku'                     => $this->clean_sku($sku),
			'regularPrice'            => $pricing['regularPrice'],
			'salePrice'               => $pricing['salePrice'],
			'saleIsActive'            => $pricing['saleIsActive'],
			'brand'                   => $brand,
			'imageUrl'                => $this->filter_image_url($this->resolve_variation_image_url($variation, $parent_product), $variation),
			'categoryExternalIds'     => $category_ids,
			'externalPurchaseUrl'     => null,
			'isPublished'             => $is_published,
			'isPurchasable'           => $variation->is_purchasable(),
			'isInStock'               => $variation->is_in_stock(),
			'isDeleted'               => $is_trash,
			'attributes'              => $this->as_json_object($this->build_attributes($variation, $has_parent ? $parent_product : null)),
		];
	}

	// MARK: Deletion Row

	/**
	 * Row for a product / variation whose post no longer exists.
	 *
	 * Regolith keeps the binding and the Item, and makes it inactive. The timestamp is "now"
	 * on purpose: it has to be newer than whatever was accepted last, otherwise the deletion
	 * comes back StaleIgnored. That's fine for determinism, the row is frozen inside a batch
	 * and retries resend the stored bytes.
	 *
	 * Categories are left empty: rows without categoryExternalIds follow the product's stored
	 * selection (LATEST doc, "Category resolution").
	 */
	public function deletion_row($product_id, $variation_id = 0)
	{
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$is_variation = $variation_id > 0;

		return [
			'externalProductId'       => 'product:' . $product_id,
			'externalVariationId'     => $is_variation ? 'variation:' . $variation_id : null,
			'parentExternalProductId' => $is_variation ? 'product:' . $product_id : null,
			'productType'             => $is_variation ? 'variation' : 'simple',
			'externalUpdatedAt'       => gmdate('Y-m-d\TH:i:s\Z'),
			'name'                    => $is_variation ? "Deleted variation #{$variation_id}" : "Deleted product #{$product_id}",
			'sku'                     => null,
			'regularPrice'            => null,
			'salePrice'               => null,
			'saleIsActive'            => false,
			'brand'                   => null,
			'imageUrl'                => null,
			'categoryExternalIds'     => [],
			'externalPurchaseUrl'     => null,
			'isPublished'             => false,
			'isPurchasable'           => false,
			'isInStock'               => false,
			'isDeleted'               => true,
			'attributes'              => new stdClass(),
		];
	}

	// MARK: Field Helpers

	/**
	 * Extract category term IDs as array of strings, sorted numerically for stable payloads.
	 */
	public function get_product_category_ids($product_id)
	{
		$terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
		if (is_wp_error($terms) || empty($terms)) {
			return [];
		}
		$terms = array_map('intval', $terms);
		sort($terms, SORT_NUMERIC);
		return array_values(array_map('strval', $terms));
	}

	/**
	 * Names are stored with HTML entities (&#8211; and friends) and the API caps them at 200.
	 * An empty name would be rejected, so fall back to something recognisable.
	 */
	private function clean_name($name, $id)
	{
		$name = trim(wp_strip_all_tags(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8')));
		if ('' === $name) {
			$name = 'Product #' . (int) $id;
		}
		return mb_substr($name, 0, self::MAX_NAME_LENGTH);
	}

	private function clean_sku($sku)
	{
		$sku = trim((string) $sku);
		return '' === $sku ? null : mb_substr($sku, 0, self::MAX_SKU_LENGTH);
	}

	/**
	 * json_encode turns an empty PHP array into [] but the API wants an object, always.
	 */
	private function as_json_object(array $attributes)
	{
		return empty($attributes) ? new stdClass() : (object) $attributes;
	}

	/**
	 * Resolve Brand name through the Product Details mapping (Brand field: source + fallback).
	 * Never configured = the usual brand taxonomies (WooCommerce core Brands is `product_brand`).
	 * Several terms (e.g. two brand terms) = the first one. Missing is fine, Regolith files it
	 * under its shared "Unknown" brand.
	 *
	 * @param WC_Product      $product Product or variation.
	 * @param WC_Product|null $parent  Parent of a variation.
	 */
	public function resolve_brand(WC_Product $product, $parent = null)
	{
		$value = Reg_Field_Mapper::I()->value_for($product, $parent, 'Brand');
		if ('' === $value) {
			return null;
		}

		$first = trim(explode(',', $value)[0]);
		return '' === $first ? null : mb_substr($first, 0, 200);
	}

	/**
	 * Resolve public image URL for a product.
	 * Hierarchy: Featured Image -> First Gallery Image -> null.
	 */
	public function resolve_image_url(WC_Product $product)
	{
		$image_id = $product->get_image_id();
		if ($image_id) {
			$url = wp_get_attachment_image_url($image_id, 'full');
			if (! empty($url)) {
				return $this->ensure_https($url);
			}
		}

		// Gallery fallback
		$gallery_ids = $product->get_gallery_image_ids();
		if (! empty($gallery_ids)) {
			$first_gallery_id = reset($gallery_ids);
			$url = wp_get_attachment_image_url($first_gallery_id, 'full');
			if (! empty($url)) {
				return $this->ensure_https($url);
			}
		}

		return null;
	}

	/**
	 * Resolve public image URL for a variation.
	 * Hierarchy: Variation Image -> Parent Image -> Parent Gallery -> null.
	 */
	public function resolve_variation_image_url(WC_Product_Variation $variation, $parent_product = null)
	{
		$image_id = $variation->get_image_id('edit');
		if ($image_id) {
			$url = wp_get_attachment_image_url($image_id, 'full');
			if (! empty($url)) {
				return $this->ensure_https($url);
			}
		}

		if ($parent_product instanceof WC_Product) {
			return $this->resolve_image_url($parent_product);
		}

		return null;
	}

	/**
	 * Last stop for every image URL. On dev sites the real URL points at a host Regolith can't
	 * reach, so we swap in a public placeholder. Filterable for anyone with a tunnel/CDN.
	 *
	 * @param string|null $url
	 * @param WC_Product  $product
	 */
	private function filter_image_url($url, $product)
	{
		if (regolith_is_dev()) {
			$url = self::DEV_PLACEHOLDER_IMAGE;
		}

		/**
		 * Filter the image URL sent to Regolith for an inventory row.
		 *
		 * @param string|null $url     Public HTTPS URL or null.
		 * @param WC_Product  $product Product or variation being serialized.
		 */
		return apply_filters('regolith_item_image_url', $url, $product);
	}

	/**
	 * Ensure URL has HTTPS protocol if available.
	 * Regolith only imports over HTTPS (IMAGE_UNSAFE otherwise).
	 */
	private function ensure_https($url)
	{
		if (empty($url)) {
			return null;
		}
		// If protocol-relative or http, switch to https
		if (strpos($url, '//') === 0) {
			return 'https:' . $url;
		}
		if (strpos($url, 'http://') === 0) {
			return 'https://' . substr($url, 7);
		}
		return $url;
	}

	/**
	 * Resolve regular price, sale price, and active sale state.
	 * Regolith derives the effective price (sale when active, else regular) and does any
	 * currency conversion, we just report what WooCommerce has.
	 */
	public function resolve_pricing(WC_Product $product)
	{
		$reg_price  = $product->get_regular_price();
		$sale_price = $product->get_sale_price();

		$regular_price = ($reg_price !== '' && is_numeric($reg_price)) ? (float) $reg_price : null;
		$sale_price_val = ($sale_price !== '' && is_numeric($sale_price)) ? (float) $sale_price : null;

		$sale_is_active = false;
		if ($sale_price_val !== null && $product->is_on_sale()) {
			$sale_is_active = true;
		}

		return [
			'regularPrice' => $regular_price,
			'salePrice'    => $sale_price_val,
			'saleIsActive' => $sale_is_active,
		];
	}

	// MARK: Attributes

	/**
	 * Build the `attributes` object.
	 *
	 * Regolith only reads its own attribute fields (the item-field-catalog, payloadSection
	 * "Attributes") and silently ignores anything else. So every catalog field is filled from
	 * the source(s) chosen in Product Details (Reg_Field_Mapper::value_for): first source with
	 * a value wins, the second one is the fallback. Never-configured fields fall back to a
	 * strict name match on the product's own attributes.
	 *
	 * For variations the variation's own value wins, the parent fills the rest. Regolith
	 * doesn't copy parent attributes to variations itself, and every write REPLACES the item's
	 * attributes, so each row has to carry the full set.
	 *
	 * Keys go out in camelCase (what the LATEST doc lists). Values are typed by the catalog
	 * dataType; anything that doesn't fit (e.g. "abc" for an int) is dropped, not guessed.
	 *
	 * @param  WC_Product      $product        Product or variation.
	 * @param  WC_Product|null $parent_product Parent of a variation.
	 * @return array Sorted key => value.
	 */
	public function build_attributes(WC_Product $product, $parent_product = null)
	{
		$mapper  = Reg_Field_Mapper::I();
		$targets = $mapper->get_attribute_targets(); // catalog key => field definition
		if (! $targets) {
			return [];
		}

		$out = [];
		foreach ($targets as $catalog_key => $target) {
			$value = $mapper->value_for($product, $parent_product, $catalog_key);
			if ('' === $value) {
				continue;
			}

			$typed = $this->cast_attribute_value($value, $target['dataType'] ?? 'string');
			if (null !== $typed) {
				$out[lcfirst($catalog_key)] = $typed;
			}
		}

		ksort($out, SORT_STRING);

		return $out;
	}

	/**
	 * Cast a source string to the catalog dataType. null = drop it.
	 */
	private function cast_attribute_value($value, $data_type)
	{
		$value = trim((string) $value);

		switch (strtolower((string) $data_type)) {
			case 'int':
			case 'integer':
				return preg_match('/^-?\d+$/', $value) ? (int) $value : null;

			case 'decimal':
			case 'double':
			case 'float':
			case 'number':
				$normalized = str_replace(',', '.', $value);
				return is_numeric($normalized) ? (float) $normalized : null;

			case 'bool':
			case 'boolean':
				$lower = strtolower($value);
				if (in_array($lower, ['1', 'yes', 'true', 'on', 'y'], true)) {
					return true;
				}
				if (in_array($lower, ['0', 'no', 'false', 'off', 'n'], true)) {
					return false;
				}
				return null;

			default:
				return mb_substr($value, 0, 500);
		}
	}

	/**
	 * Get ISO-8601 UTC timestamp from product modification time.
	 * Format: YYYY-MM-DDTHH:MM:SSZ (fixed width, so string comparison = time comparison).
	 *
	 * Never "now" for an existing product: a new timestamp on every attempt would make every
	 * sync look like a change and defeat stale-update protection.
	 */
	public function get_iso_updated_at(WC_Product $product)
	{
		$date_modified = $product->get_date_modified();
		if ($date_modified instanceof WC_DateTime) {
			return gmdate('Y-m-d\TH:i:s\Z', $date_modified->getTimestamp());
		}

		$post_id = $product->get_id();
		$gmt_date = get_post_field('post_modified_gmt', $post_id);
		if (! empty($gmt_date) && $gmt_date !== '0000-00-00 00:00:00') {
			$ts = strtotime($gmt_date . ' GMT');
			if ($ts) {
				return gmdate('Y-m-d\TH:i:s\Z', $ts);
			}
		}

		$created = $product->get_date_created();
		if ($created instanceof WC_DateTime) {
			return gmdate('Y-m-d\TH:i:s\Z', $created->getTimestamp());
		}

		return '1970-01-01T00:00:00Z';
	}
}
