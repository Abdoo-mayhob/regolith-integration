<?php

/**
 * Product Details Mapper
 *
 * Decides where each Regolith item field comes from on THIS store. Real WooCommerce sites keep
 * product specs all over the place: global attributes, per-product attributes, brand plugins'
 * taxonomies, ACF / custom fields, sometimes half in one and half in another. Getting this
 * right before the first Full sync is what makes the listing accurate, so the wizard makes it
 * a required step.
 *
 * The model is target-first, because that's how a store owner thinks ("where do I keep RAM?"):
 *
 *   option regolith_field_map = [ catalog key => [ source id, fallback source id ] ]
 *     - first source with a value wins, per product
 *     - an empty list means "my store doesn't have this", nothing is sent
 *     - a key missing from the map means "never configured": we auto-match by name
 *       (only happens before the wizard step is confirmed)
 *
 * Targets come from GET /item-field-catalog (Regolith builds it from its sync DTOs). We offer
 * Brand, SKU (fallback when the WooCommerce SKU is empty) and every "Attributes" field.
 * Name, prices, image, stock come straight from WooCommerce and aren't mappable.
 *
 * Source ids:
 *   pa_{name}        global attribute (taxonomy)
 *   custom:{slug}    per-product attribute
 *   tax:{taxonomy}   any other product taxonomy (brands, tags...)
 *   meta:{key}       a custom field (post meta)
 *   wc:{prop}        WooCommerce core data: weight, length, width, height, global_unique_id
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Field_Mapper
{
	/**
	 * Taxonomies we read the brand from when Brand was never configured.
	 * `product_brand` is WooCommerce core Brands (9.6+), the rest are popular plugins.
	 */
	const BRAND_TAXONOMIES = ['product_brand', 'brand', 'pwb-brand', 'yith_product_brand'];

	const OPTION         = 'regolith_field_map';
	const LEGACY_OPTION  = 'regolith_field_mapping';
	const DISCOVERY_KEY  = 'regolith_field_sources';

	/**
	 * Root (non-attribute) catalog fields the store owner may map.
	 */
	const ROOT_TARGETS = ['Brand', 'Sku'];

	/**
	 * WooCommerce core properties offered as sources. Dimensions get their unit appended.
	 */
	const WC_SOURCES = [
		'wc:weight'           => ['label' => 'Weight (WooCommerce shipping)', 'meta' => '_weight', 'unit' => 'woocommerce_weight_unit'],
		'wc:length'           => ['label' => 'Length (WooCommerce shipping)', 'meta' => '_length', 'unit' => 'woocommerce_dimension_unit'],
		'wc:width'            => ['label' => 'Width (WooCommerce shipping)', 'meta' => '_width', 'unit' => 'woocommerce_dimension_unit'],
		'wc:height'           => ['label' => 'Height (WooCommerce shipping)', 'meta' => '_height', 'unit' => 'woocommerce_dimension_unit'],
		'wc:global_unique_id' => ['label' => 'GTIN / UPC / EAN / ISBN (WooCommerce)', 'meta' => '_global_unique_id', 'unit' => null],
	];

	/**
	 * Extra names a field is commonly stored under, on top of its own key/label.
	 * Compared after normalising (letters+digits, lowercase).
	 */
	const SYNONYMS = [
		'Brand'                       => ['brand', 'brands', 'manufacturer', 'make', 'productbrand', 'brandname'],
		'Sku'                         => ['mpn', 'partnumber', 'manufacturerpartnumber', 'itemcode'],
		'Model'                       => ['model', 'modelname', 'modelnumber'],
		'ProductModelNameOrNumber'    => ['modelnumber', 'modelno', 'mpn', 'partnumber'],
		'Color'                       => ['color', 'colour', 'colors', 'colours'],
		'Year'                        => ['year', 'modelyear', 'releaseyear'],
		'Weight'                      => ['weight', 'wcweight', 'netweight'],
		'Length'                      => ['length', 'wclength', 'depth'],
		'Breadth'                     => ['breadth', 'width', 'wcwidth'],
		'Height'                      => ['height', 'wcheight'],
		'Warranty'                    => ['warranty', 'warrantyperiod', 'guarantee'],
		'MemorySize'                  => ['ram', 'ramsize', 'memory', 'memorysize', 'rammemory'],
		'StorageCapacity'             => ['storage', 'storagesize', 'harddisk', 'hdd', 'ssd', 'capacity', 'disksize'],
		'ProcessorModel'              => ['processor', 'cpu', 'cpumodel'],
		'DisplaySize'                 => ['screensize', 'displaysize', 'screen'],
		'DisplayResolution'           => ['resolution', 'screenresolution'],
		'OperatingSystem'             => ['os', 'operatingsystem'],
		'GraphicsCardProcessor'       => ['gpu', 'graphics', 'graphicscard'],
		'ProductCountryOfManufacture' => ['countryoforigin', 'madein', 'origin', 'countryofmanufacture'],
	];

	/**
	 * Meta key prefixes that are WordPress / WooCommerce / SEO-plugin plumbing, never product specs.
	 */
	const META_DENYLIST = [
		'_sku', '_price', '_regular_price', '_sale_price', '_tax_', '_manage_stock', '_backorders', '_stock',
		'_low_stock', '_sold_individually', '_weight', '_length', '_width', '_height', '_upsell', '_crosssell',
		'_purchase_note', '_default_attributes', '_virtual', '_downloadable', '_download', '_product_image_gallery',
		'_wc_', '_product_version', '_product_attributes', '_thumbnail_id', '_edit_', '_wp_', '_children',
		'_product_url', '_button_text', '_variation_description', '_regolith', '_wpcom', '_global_unique_id',
		'total_sales', 'attribute_', '_yoast', '_elementor', 'rank_math', '_aioseo', '_oembed', '_menu_item',
		'_transient', '_encloseme', '_pingme', '_trp_', '_jetpack', '_last_editor',
	];

	/**
	 * Per-request memo of the attribute targets, the serializer asks once per row.
	 *
	 * @var array|null
	 */
	private $targets = null;

	/**
	 * Same, for get_mappable_targets().
	 *
	 * @var array|null
	 */
	private $mappable = null;

	/**
	 * Temporary map used by the live preview (unsaved form values).
	 *
	 * @var array|null
	 */
	private $override = null;

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

	public function __construct()
	{
		add_action('wp_ajax_regolith_save_fields', [$this, 'ajax_save_fields']);
		add_action('wp_ajax_regolith_preview_product', [$this, 'ajax_preview_product']);
		add_action('wp_ajax_regolith_rescan_fields', [$this, 'ajax_rescan']);
	}

	// MARK: Catalog

	/**
	 * The whole field catalog, flat. Falls back to the last good copy when the API is down:
	 * an empty catalog would make every row go out with `attributes: {}` and, since every
	 * write REPLACES attributes on Regolith's side, wipe them all.
	 *
	 * @return array[]
	 */
	public function get_catalog()
	{
		$last_good = get_option('regolith_item_field_catalog_last', []);

		// A refresh failed recently: don't hammer the API from inside a sync run.
		if (get_transient('regolith_item_field_catalog_backoff')) {
			return is_array($last_good) ? $last_good : [];
		}

		$catalog = Reg_API::I()->get_item_field_catalog();

		if (is_wp_error($catalog) || ! $catalog) {
			set_transient('regolith_item_field_catalog_backoff', 1, 5 * MINUTE_IN_SECONDS);
			return is_array($last_good) ? $last_good : [];
		}

		if ($catalog !== $last_good) {
			update_option('regolith_item_field_catalog_last', $catalog, false);
		}

		return $catalog;
	}

	/**
	 * Catalog fields that go into `attributes`, keyed by catalog key.
	 */
	public function get_attribute_targets()
	{
		if (null !== $this->targets) {
			return $this->targets;
		}

		$this->targets = [];
		foreach ($this->get_catalog() as $field) {
			if (0 === strcasecmp((string) ($field['payloadSection'] ?? ''), 'Attributes')) {
				$this->targets[$field['key']] = $field;
			}
		}

		return $this->targets;
	}

	/**
	 * Everything the store owner can map: Brand, Sku, then every attribute field.
	 */
	public function get_mappable_targets()
	{
		if (null !== $this->mappable) {
			return $this->mappable;
		}

		$targets = [];
		foreach ($this->get_catalog() as $field) {
			if (in_array($field['key'] ?? '', self::ROOT_TARGETS, true)) {
				$targets[$field['key']] = $field;
			}
		}
		// Brand/Sku always exist even if the catalog is unreachable on first load.
		foreach (self::ROOT_TARGETS as $key) {
			if (! isset($targets[$key])) {
				$targets[$key] = ['key' => $key, 'label' => $key, 'group' => 'Core Product Fields', 'payloadSection' => 'Root', 'dataType' => 'string'];
			}
		}

		return $this->mappable = $targets + $this->get_attribute_targets();
	}

	// MARK: Saved Map

	/**
	 * The saved map (catalog key => source ids), migrating the old source => key option once.
	 */
	public function get_field_map()
	{
		if (null !== $this->override) {
			return $this->override;
		}

		$map = get_option(self::OPTION, null);
		if (is_array($map)) {
			return $map;
		}

		// One-time migration from the first mapper (source_id => catalog key).
		$map = [];
		foreach ((array) get_option(self::LEGACY_OPTION, []) as $source_id => $key) {
			if (is_string($key) && '' !== $key && '__skip__' !== $key) {
				$map[$key][] = (string) $source_id;
			}
		}
		return $map;
	}

	public function is_confirmed()
	{
		return (bool) get_option('regolith_fields_confirmed', 0);
	}

	/**
	 * Count of fields that will actually be filled from somewhere.
	 */
	public function count_mapped()
	{
		return count(array_filter($this->get_field_map(), static fn ($sources) => ! empty($sources)));
	}

	/**
	 * Run $callback with a temporary (unsaved) map, used by the live preview.
	 */
	public function with_override(array $map, callable $callback)
	{
		$previous       = $this->override;
		$this->override = $map;
		try {
			return $callback();
		} finally {
			$this->override = $previous;
		}
	}

	// MARK: Values

	/**
	 * The value of a catalog field for a product / variation, as a string ('' = nothing).
	 *
	 * @param WC_Product      $product The product, or the variation.
	 * @param WC_Product|null $parent  The variation's parent.
	 * @param string          $key     Catalog key.
	 */
	public function value_for(WC_Product $product, $parent, $key)
	{
		$base = $parent ?: $product;

		foreach ($this->sources_for_key($key, $base) as $source_id) {
			$value = '';
			if ($parent) {
				$value = $this->read_variation_value($product, $source_id);
			}
			if ('' === $value) {
				$value = $this->read_source_value($base, $source_id);
			}
			if ('' !== $value) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Sources to try for a key: the configured list, or (never configured) an automatic match
	 * against this product's attributes / the brand taxonomies.
	 */
	public function sources_for_key($key, WC_Product $product)
	{
		$map = $this->get_field_map();
		if (array_key_exists($key, $map)) {
			return array_values(array_filter((array) $map[$key], 'strlen'));
		}

		$sources = [];

		if ('Brand' === $key) {
			foreach (self::BRAND_TAXONOMIES as $taxonomy) {
				if (taxonomy_exists($taxonomy)) {
					$sources[] = 'tax:' . $taxonomy;
				}
			}
		}

		$target = $this->get_mappable_targets()[$key] ?? ['key' => $key, 'label' => $key];
		foreach ($product->get_attributes() as $slug => $attribute) {
			if (! $attribute instanceof WC_Product_Attribute) {
				continue;
			}
			$label = $attribute->is_taxonomy() ? wc_attribute_label($attribute->get_name()) : $attribute->get_name();
			if ($this->name_matches($label, $target)) {
				$sources[] = $attribute->is_taxonomy() ? $attribute->get_name() : 'custom:' . sanitize_title($slug);
			}
		}

		return $sources;
	}

	/**
	 * Read a source's value for a product as a plain string ('' = nothing there).
	 * Multi-value sources (several terms / options) are joined with ", " in stored order.
	 */
	public function read_source_value(WC_Product $product, $source_id)
	{
		$product_id = $product->get_id();
		$values     = [];

		if (0 === strpos($source_id, 'pa_')) {
			$terms  = wc_get_product_terms($product_id, $source_id, ['fields' => 'names']);
			$values = is_wp_error($terms) ? [] : $terms;
		} elseif (0 === strpos($source_id, 'custom:')) {
			$slug = substr($source_id, 7);
			foreach ($product->get_attributes() as $attr_slug => $attribute) {
				if ($attribute instanceof WC_Product_Attribute && ! $attribute->is_taxonomy() && sanitize_title($attr_slug) === $slug) {
					$values = $attribute->get_options();
				}
			}
		} elseif (0 === strpos($source_id, 'tax:')) {
			$taxonomy = substr($source_id, 4);
			if (taxonomy_exists($taxonomy)) {
				$terms  = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names', 'orderby' => 'term_id']);
				$values = is_wp_error($terms) ? [] : $terms;
			}
		} elseif (0 === strpos($source_id, 'meta:')) {
			$values = [$this->scalar_meta($product_id, substr($source_id, 5))];
		} elseif (0 === strpos($source_id, 'wc:')) {
			$values = [$this->read_wc_value($product, $source_id, 'view')];
		} elseif (taxonomy_exists($source_id)) {
			// Legacy un-prefixed taxonomy id (old saved mappings).
			$terms  = wp_get_post_terms($product_id, $source_id, ['fields' => 'names', 'orderby' => 'term_id']);
			$values = is_wp_error($terms) ? [] : $terms;
		}

		$values = array_filter(array_map(static fn ($v) => trim(html_entity_decode(wp_strip_all_tags((string) $v), ENT_QUOTES, 'UTF-8')), (array) $values), 'strlen');

		return implode(', ', $values);
	}

	/**
	 * A variation's own value for a source ('' when it has none / "any").
	 * Variations store attribute values as term SLUGS for taxonomies and plain text for
	 * custom attributes. Custom fields and dimensions are read from the variation itself.
	 */
	public function read_variation_value(WC_Product $variation, $source_id)
	{
		if (! $variation instanceof WC_Product_Variation) {
			return '';
		}

		if (0 === strpos($source_id, 'meta:')) {
			return trim($this->scalar_meta($variation->get_id(), substr($source_id, 5)));
		}

		if (0 === strpos($source_id, 'wc:')) {
			// 'edit' = the variation's own value, no inheritance from the parent.
			return trim($this->read_wc_value($variation, $source_id, 'edit'));
		}

		$attributes = $variation->get_attributes(); // [ 'pa_color' => 'blue', 'logo' => 'Yes' ]

		if (0 === strpos($source_id, 'pa_')) {
			$slug = $attributes[$source_id] ?? '';
			if ('' === $slug) {
				return '';
			}
			$term = get_term_by('slug', $slug, $source_id);
			return ($term && ! is_wp_error($term)) ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : (string) $slug;
		}

		if (0 === strpos($source_id, 'custom:')) {
			$slug = substr($source_id, 7);
			foreach ($attributes as $name => $value) {
				if (sanitize_title($name) === $slug) {
					return trim((string) $value);
				}
			}
		}

		return '';
	}

	/**
	 * WooCommerce core property as text, with its unit ("1.5 kg").
	 */
	private function read_wc_value(WC_Product $product, $source_id, $context)
	{
		$prop = substr($source_id, 3);
		$def  = self::WC_SOURCES[$source_id] ?? null;
		if (! $def) {
			return '';
		}

		$getter = 'get_' . $prop;
		$value  = method_exists($product, $getter) ? (string) $product->$getter($context) : '';
		if ('' === trim($value)) {
			return '';
		}

		return $def['unit'] ? trim($value) . ' ' . get_option($def['unit'], '') : trim($value);
	}

	/**
	 * Post meta as a string. Arrays (ACF checkboxes, repeaters) are flattened one level,
	 * anything deeper is ignored rather than sent as "Array".
	 */
	private function scalar_meta($post_id, $key)
	{
		$meta = get_post_meta($post_id, $key, true);
		if (is_scalar($meta)) {
			return (string) $meta;
		}
		if (is_array($meta)) {
			return implode(', ', array_filter($meta, 'is_scalar'));
		}
		return '';
	}

	// MARK: Matching

	private function normalize($text)
	{
		return preg_replace('/[^a-z0-9]/', '', strtolower(remove_accents((string) $text)));
	}

	/**
	 * Does a source name match a catalog field? Exact match on the normalised key, label or a
	 * known synonym. Deliberately strict: a wrong automatic match is worse than none.
	 */
	private function name_matches($name, array $target)
	{
		$needle = $this->normalize($name);
		if ('' === $needle) {
			return false;
		}

		$names = [$this->normalize($target['key'] ?? '')];

		// Labels only count when no other field shares them: "Weight" is the label of Weight,
		// SolarWeight and BatteryWeight alike, and a shipping weight belongs to the first only.
		$label = $this->normalize($target['label'] ?? '');
		if ('' !== $label && ! isset($this->ambiguous_labels()[$label])) {
			$names[] = $label;
		}

		foreach (self::SYNONYMS[$target['key'] ?? ''] ?? [] as $synonym) {
			$names[] = $synonym;
		}

		return in_array($needle, array_filter($names), true);
	}

	/**
	 * Normalised labels used by more than one target field.
	 */
	private function ambiguous_labels()
	{
		static $memo = null;
		if (null === $memo) {
			$counts = [];
			foreach ($this->get_mappable_targets() as $field) {
				$label = $this->normalize($field['label'] ?? '');
				$counts[$label] = ($counts[$label] ?? 0) + 1;
			}
			$memo = array_filter($counts, static fn ($n) => $n > 1);
		}
		return $memo;
	}

	/**
	 * Suggested sources for every mappable field, from what discovery found on this store.
	 * Best coverage first; at most two (primary + fallback).
	 *
	 * @return array key => [source ids]
	 */
	public function suggest()
	{
		$sources     = $this->discover_sources();
		$suggestions = [];

		foreach ($this->get_mappable_targets() as $key => $target) {
			$hits = [];
			foreach ($sources as $source) {
				$name = $source['match_name'] ?? $source['label'];
				if ($this->name_matches($name, $target) || $this->name_matches($source['label'], $target)) {
					$hits[] = $source;
				}
			}

			if ('Brand' === $key) {
				foreach ($sources as $source) {
					if (in_array(substr($source['id'], 4), self::BRAND_TAXONOMIES, true)) {
						$hits[] = $source;
					}
				}
			}

			usort($hits, static fn ($a, $b) => $b['count'] <=> $a['count']);
			$ids = array_values(array_unique(array_column($hits, 'id')));
			if ($ids) {
				$suggestions[$key] = array_slice($ids, 0, 2);
			}
		}

		return $suggestions;
	}

	// MARK: Discovery

	/**
	 * Everything on this store that holds product data, with how many products use it and an
	 * example value. Cached for 10 minutes (the scan reads postmeta), "Rescan" clears it.
	 *
	 * @return array[] { id, label, type, count, sample, match_name }
	 */
	public function discover_sources($force = false)
	{
		if (! $force) {
			$cached = get_transient(self::DISCOVERY_KEY);
			if (is_array($cached)) {
				return $cached;
			}
		}

		global $wpdb;
		$sources = [];

		// 1. Global attributes (taxonomies pa_*).
		if (function_exists('wc_get_attribute_taxonomies')) {
			foreach (wc_get_attribute_taxonomies() as $attr) {
				$taxonomy = 'pa_' . $attr->attribute_name;
				$sources[] = $this->taxonomy_source($taxonomy, $taxonomy, $attr->attribute_label, 'Attribute', $attr->attribute_name);
			}
		}

		// 2. Per-product (custom) attributes, from _product_attributes.
		$custom = [];
		$rows   = $wpdb->get_col("
			SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_product_attributes' AND p.post_type = 'product' AND p.post_status <> 'trash'
			LIMIT 5000
		");
		foreach ($rows as $raw) {
			foreach ((array) maybe_unserialize($raw) as $slug => $attr) {
				if (! is_array($attr) || ! empty($attr['is_taxonomy']) || empty($attr['name'])) {
					continue;
				}
				$id = 'custom:' . sanitize_title($slug);
				if (! isset($custom[$id])) {
					$custom[$id] = ['id' => $id, 'label' => $attr['name'], 'type' => 'Custom attribute', 'count' => 0, 'sample' => '', 'match_name' => $attr['name']];
				}
				$custom[$id]['count']++;
				if ('' === $custom[$id]['sample'] && ! empty($attr['value'])) {
					$custom[$id]['sample'] = mb_substr(str_replace('|', ',', (string) $attr['value']), 0, 60);
				}
			}
		}
		$sources = array_merge($sources, array_values($custom));

		// 3. Other product taxonomies (brands, tags, plugin taxonomies).
		$skip_tax = ['product_cat', 'product_type', 'product_visibility', 'product_shipping_class'];
		foreach (get_object_taxonomies('product', 'objects') as $tax) {
			if (0 === strpos($tax->name, 'pa_') || in_array($tax->name, $skip_tax, true)) {
				continue;
			}
			$sources[] = $this->taxonomy_source('tax:' . $tax->name, $tax->name, $tax->label, 'Taxonomy', $tax->name);
		}

		// 4. Custom fields with a value on at least one product / variation.
		$meta = $wpdb->get_results("
			SELECT pm.meta_key AS k, COUNT(DISTINCT pm.post_id) AS c, MAX(LEFT(pm.meta_value, 80)) AS s
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation') AND p.post_status <> 'trash' AND pm.meta_value <> ''
			GROUP BY pm.meta_key
			ORDER BY c DESC
			LIMIT 600
		", ARRAY_A);

		$keys = array_flip(array_column((array) $meta, 'k'));
		foreach ((array) $meta as $row) {
			$key    = (string) $row['k'];
			$sample = (string) $row['s'];

			if ($this->is_plumbing_meta($key)) {
				continue;
			}
			// ACF keeps "_field" => "field_abc123" next to every "field". Only the real one counts.
			if (0 === strpos($sample, 'field_') || ('_' === $key[0] && isset($keys[substr($key, 1)]))) {
				continue;
			}
			// Serialized blobs aren't specs.
			if (preg_match('/^(a|O|s):\d+:/', $sample)) {
				continue;
			}

			$sources[] = [
				'id'         => 'meta:' . $key,
				'label'      => ucfirst(trim(str_replace(['_', '-'], ' ', $key))),
				'type'       => 'Custom field',
				'count'      => (int) $row['c'],
				'sample'     => mb_substr(wp_strip_all_tags($sample), 0, 60),
				'match_name' => $key,
			];
		}

		// 5. WooCommerce core data.
		foreach (self::WC_SOURCES as $id => $def) {
			$count = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_status <> 'trash'",
				$def['meta']
			));
			$sample = (string) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1", $def['meta']));
			$sources[] = [
				'id'         => $id,
				'label'      => $def['label'],
				'type'       => 'WooCommerce',
				'count'      => $count,
				'sample'     => $sample . ($sample && $def['unit'] ? ' ' . get_option($def['unit'], '') : ''),
				'match_name' => substr($id, 3),
			];
		}

		// Unused sources are noise (an empty attribute nobody assigned).
		$sources = array_values(array_filter($sources, static fn ($s) => $s['count'] > 0));

		set_transient(self::DISCOVERY_KEY, $sources, 10 * MINUTE_IN_SECONDS);

		return $sources;
	}

	private function taxonomy_source($id, $taxonomy, $label, $type, $match_name)
	{
		global $wpdb;

		$count = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tt.taxonomy = %s",
			$taxonomy
		));
		$sample = (string) $wpdb->get_var($wpdb->prepare(
			"SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			WHERE tt.taxonomy = %s AND tt.count > 0 ORDER BY tt.count DESC LIMIT 1",
			$taxonomy
		));

		return [
			'id'         => $id,
			'label'      => html_entity_decode((string) $label, ENT_QUOTES, 'UTF-8'),
			'type'       => $type,
			'count'      => $count,
			'sample'     => html_entity_decode($sample, ENT_QUOTES, 'UTF-8'),
			'match_name' => $match_name,
		];
	}

	private function is_plumbing_meta($key)
	{
		foreach (self::META_DENYLIST as $prefix) {
			if (0 === strpos($key, $prefix)) {
				return true;
			}
		}
		return false;
	}

	// MARK: Form

	/**
	 * The product-details form, shared by the wizard step and Settings → Product details.
	 *
	 * Selects are rendered with just their current option; assets/admin.js fills them from the
	 * shared source list (window.regolithSources) on first focus. 90 fields × 2 selects × a few
	 * hundred sources would otherwise be tens of thousands of <option>s.
	 *
	 * @param string $context 'wizard' | 'settings' (button labels only)
	 */
	public function render_form($context = 'settings')
	{
		$targets = $this->get_mappable_targets();
		$sources = $this->discover_sources();
		$by_id   = array_column($sources, null, 'id');

		$confirmed = $this->is_confirmed();
		$saved     = $this->get_field_map();
		// Before the first confirmation, pre-fill with suggestions. After it, show exactly what's saved.
		$values    = $confirmed ? $saved : $saved + $this->suggest();
		$suggested = $confirmed ? [] : $this->suggest();

		$groups = [];
		foreach ($targets as $key => $field) {
			$group = in_array($key, self::ROOT_TARGETS, true) ? 'Brand & SKU' : ($field['group'] ?? 'Other');
			$groups[$group][$key] = $field;
		}

		$products = get_posts([
			'post_type'      => 'product',
			'post_status'    => ['publish', 'private', 'draft'],
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
		]);
		?>
		<div class="reg-fields" data-reg-fields data-context="<?php echo esc_attr($context); ?>">
			<script>
				window.regolithSources = <?php echo wp_json_encode(array_map(static fn ($s) => [
					'id'     => $s['id'],
					'label'  => $s['label'],
					'type'   => $s['type'],
					'count'  => $s['count'],
					'sample' => $s['sample'],
				], $sources)); ?>;
			</script>

			<?php if (! $targets || count($targets) <= count(self::ROOT_TARGETS)) : ?>
				<div class="reg-alert reg-alert--warn">We couldn't load Regolith's list of product fields. Check the connection and reload this page.</div>
			<?php endif; ?>

			<div class="reg-fields__layout">
				<div class="reg-fields__main">
					<div class="reg-fields__toolbar">
						<input type="search" class="reg-input" placeholder="Find a field (e.g. memory, warranty)…" data-reg-field-search>
						<span class="reg-muted"><?php echo esc_html(count($sources)); ?> data sources found on your store.
							<a href="#" data-reg-rescan>Rescan</a></span>
					</div>

					<form data-reg-fields-form>
						<?php foreach ($groups as $group => $fields) :
							$filled = count(array_filter(array_keys($fields), static fn ($k) => ! empty($values[$k])));
							$open   = $filled > 0 || 'Brand & SKU' === $group;
							?>
							<details class="reg-group" <?php echo $open ? 'open' : ''; ?>>
								<summary>
									<span class="reg-group__title"><?php echo esc_html($group); ?></span>
									<span class="reg-group__count" data-reg-group-count><?php echo esc_html($filled . ' of ' . count($fields)); ?></span>
								</summary>
								<div class="reg-group__body">
									<?php foreach ($fields as $key => $field) :
										$current = array_values((array) ($values[$key] ?? []));
										?>
										<div class="reg-field-row" data-reg-field="<?php echo esc_attr($key); ?>" data-search="<?php echo esc_attr(strtolower($key . ' ' . ($field['label'] ?? '') . ' ' . $group)); ?>">
											<div class="reg-field-row__label">
												<strong><?php echo esc_html('Sku' === $key ? 'SKU / part number' : ($field['label'] ?? $key)); ?></strong>
												<?php if (isset($suggested[$key])) : ?><?php echo Reg_UI::pill('Suggested', 'info'); ?><?php endif; ?>
												<?php if ('Sku' === $key) : ?><div class="reg-muted">Only used when the WooCommerce SKU is empty.</div><?php endif; ?>
												<?php if ('Brand' === $key) : ?><div class="reg-muted">Products without a brand are listed under "Unknown".</div><?php endif; ?>
											</div>
											<div class="reg-field-row__sources">
												<?php for ($i = 0; $i < 2; $i++) :
													$sel = $current[$i] ?? '';
													$src = $by_id[$sel] ?? null;
													?>
													<select name="map[<?php echo esc_attr($key); ?>][]" class="reg-select" data-reg-source-select data-slot="<?php echo (int) $i; ?>" <?php echo (1 === $i && '' === ($current[0] ?? '')) ? 'hidden' : ''; ?>>
														<option value=""><?php echo 0 === $i ? 'Not on my store' : 'No fallback'; ?></option>
														<?php if ($sel) : ?>
															<option value="<?php echo esc_attr($sel); ?>" selected><?php echo esc_html($this->source_option_label($sel, $src)); ?></option>
														<?php endif; ?>
													</select>
												<?php endfor; ?>
											</div>
										</div>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endforeach; ?>
					</form>
				</div>

				<aside class="reg-fields__preview">
					<div class="reg-card reg-card--sticky">
						<h3>Preview</h3>
						<p class="reg-muted">See exactly what Regolith will receive for a product, with your current choices (saved or not).</p>
						<select class="reg-select" data-reg-preview-product>
							<option value="">Choose a product…</option>
							<?php foreach ($products as $pid) : ?>
								<option value="<?php echo (int) $pid; ?>"><?php echo esc_html(wp_strip_all_tags(get_the_title($pid)) . ' (#' . $pid . ')'); ?></option>
							<?php endforeach; ?>
						</select>
						<div class="reg-preview" data-reg-preview><p class="reg-muted">Nothing to show yet.</p></div>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * "Memory · Attribute · 87 products · e.g. 16 GB"
	 */
	private function source_option_label($id, $source)
	{
		if (! $source) {
			return $id . ' (not found on your store any more)';
		}
		return sprintf('%s · %s · %d products%s', $source['label'], $source['type'], $source['count'], $source['sample'] !== '' ? ' · e.g. ' . $source['sample'] : '');
	}

	// MARK: AJAX

	/**
	 * Clean a posted map: known keys only, at most two non-empty, distinct, well-formed sources.
	 */
	private function sanitize_posted_map($posted)
	{
		$targets = $this->get_mappable_targets();
		$map     = [];

		foreach ((array) $posted as $key => $sources) {
			$key = sanitize_text_field((string) $key);
			if (! isset($targets[$key])) {
				continue;
			}
			$clean = [];
			foreach ((array) $sources as $source) {
				$source = sanitize_text_field((string) $source);
				if ('' !== $source && preg_match('/^(pa_|custom:|tax:|meta:|wc:)/', $source) && ! in_array($source, $clean, true)) {
					$clean[] = $source;
				}
			}
			$map[$key] = array_slice($clean, 0, 2);
		}

		// Every field gets an explicit entry once confirmed, so nothing is auto-matched later.
		foreach (array_keys($targets) as $key) {
			if (! isset($map[$key])) {
				$map[$key] = [];
			}
		}

		return $map;
	}

	public function ajax_save_fields()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}

		$map = $this->sanitize_posted_map(wp_unslash($_POST['map'] ?? []));
		if (! $map) {
			wp_send_json_error('Regolith\'s field list isn\'t available right now. Reload the page and try again.');
		}

		$changed = $map !== get_option(self::OPTION);
		update_option(self::OPTION, $map, false);
		update_option('regolith_fields_confirmed', time(), false);

		// After go-live, a mapping change only reaches Regolith when products are re-sent.
		if ($changed && Reg_Wizard::I()->is_complete()) {
			update_option('regolith_fields_changed_at', time(), false);
		}

		wp_send_json_success([
			'mapped'  => count(array_filter($map)),
			'message' => sprintf('Saved. %d product details will be sent to Regolith.', count(array_filter($map))),
		]);
	}

	/**
	 * Live preview with the unsaved form values.
	 */
	public function ajax_preview_product()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}

		$product_id = absint($_POST['product_id'] ?? 0);
		$product    = wc_get_product($product_id);
		if (! $product) {
			wp_send_json_error('Product not found.');
		}

		$map  = $this->sanitize_posted_map(wp_unslash($_POST['map'] ?? []));
		$rows = $this->with_override($map, static fn () => Reg_Product_Serializer::I()->rows_for_product($product));

		wp_send_json_success(['html' => $this->render_preview($product, $rows)]);
	}

	public function ajax_rescan()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}
		delete_transient('regolith_item_field_catalog_backoff');
		Reg_API::I()->get_item_field_catalog(true);
		$this->discover_sources(true);
		wp_send_json_success(['message' => 'Rescanned.']);
	}

	/**
	 * Human-readable version of the rows a product turns into.
	 */
	private function render_preview(WC_Product $product, array $rows)
	{
		$targets = $this->get_mappable_targets();
		$labels  = [];
		foreach ($targets as $key => $field) {
			$labels[lcfirst($key)] = $field['label'] ?? $key;
		}

		ob_start();

		if (! $rows) {
			echo '<p class="reg-muted">';
			echo $product->is_type('grouped')
				? 'Grouped products aren\'t listed themselves, each product inside the group is listed on its own.'
				: 'This product isn\'t sent to Regolith.';
			echo '</p>';
			return ob_get_clean();
		}

		if (count($rows) > 1) {
			echo '<p class="reg-muted">' . esc_html(count($rows)) . ' variations, each is listed as its own item. Showing the first 3.</p>';
		}

		foreach (array_slice($rows, 0, 3) as $row) {
			$row        = json_decode(wp_json_encode($row), true);
			$categories = array_map(static function ($id) {
				$term = get_term((int) $id, 'product_cat');
				return $term && ! is_wp_error($term) ? html_entity_decode($term->name, ENT_QUOTES, 'UTF-8') : $id;
			}, (array) $row['categoryExternalIds']);
			$price = $row['saleIsActive'] && null !== $row['salePrice'] ? $row['salePrice'] : $row['regularPrice'];
			?>
			<div class="reg-preview__item">
				<div class="reg-preview__head">
					<?php if (! empty($row['imageUrl'])) : ?><img src="<?php echo esc_url($row['imageUrl']); ?>" alt="" loading="lazy"><?php endif; ?>
					<div>
						<strong><?php echo esc_html($row['name']); ?></strong><br>
						<span class="reg-muted"><?php echo esc_html($row['sku'] ?: 'No SKU'); ?></span>
					</div>
				</div>
				<dl class="reg-dl">
					<dt>Price</dt><dd><?php echo null === $price ? '<span class="reg-text-warn">No price, won\'t be listed</span>' : esc_html(get_woocommerce_currency() . ' ' . wc_format_decimal($price, 2)); ?></dd>
					<dt>Brand</dt><dd><?php echo $row['brand'] ? esc_html($row['brand']) : '<span class="reg-muted">Unknown</span>'; ?></dd>
					<dt>Categories</dt><dd><?php echo esc_html(implode(', ', $categories) ?: '—'); ?></dd>
					<dt>Availability</dt><dd><?php echo esc_html(implode(', ', array_filter([
						$row['isPublished'] ? 'Published' : 'Not published',
						$row['isInStock'] ? 'In stock' : 'Out of stock',
						$row['isPurchasable'] ? null : 'Not purchasable',
					]))); ?></dd>
					<?php foreach ((array) $row['attributes'] as $k => $v) : ?>
						<dt><?php echo esc_html($labels[$k] ?? $k); ?></dt><dd><?php echo esc_html(is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v); ?></dd>
					<?php endforeach; ?>
				</dl>
				<?php if (! $row['attributes']) : ?>
					<p class="reg-muted">No product details found for this product with the current choices.</p>
				<?php endif; ?>
			</div>
			<?php
		}

		return ob_get_clean();
	}
}
