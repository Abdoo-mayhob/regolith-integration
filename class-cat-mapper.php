<?php

/**
 * Category Mapping (component)
 *
 * Used by the setup wizard (Categories + "Products in several categories" steps) and by
 * Settings → Categories. Two pieces:
 *
 * 1. Mapping: every active WooCommerce category is either matched to a Regolith category or
 *    marked "Don't list on Regolith" (the API's Ignored). Undecided ones get a suggestion by
 *    name. The server refuses to finish onboarding while anything is undecided, and so do we
 *    (the "safe" go-live rule).
 * 2. Selections: products whose categories point at DIFFERENT Regolith categories. Regolith
 *    won't guess, each needs a pick. Variations follow their parent.
 *
 * IMPORTANT: the mapping endpoints take vendorCategoryId (Regolith's id for our category), not
 * the WooCommerce term id. GET category-mappings gives us both.
 *
 * @package Regolith_Integration
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reg_Category_Mapper
{
	const IGNORE = '__ignored__';

	public static function I(): self
	{
		static $instance;

		return $instance ??= new self();
	}

	public function __construct()
	{
		add_action('wp_ajax_regolith_save_categories', [$this, 'ajax_save_categories']);
		add_action('wp_ajax_regolith_save_selections', [$this, 'ajax_save_selections']);
	}

	// MARK: State

	/**
	 * Fresh mapping state from Regolith, with the category snapshot re-sent first when our
	 * categories changed (or were never sent). Without that, a new WooCommerce category simply
	 * wouldn't appear in the list.
	 *
	 * @return array|WP_Error `data` of GET category-mappings.
	 */
	public function get_state()
	{
		if (! get_option('regolith_latest_category_snapshot_id') || Reg_Onboarding::I()->is_dirty('categories')) {
			$sent = Reg_API::I()->sync_category_snapshot();
			if (is_wp_error($sent)) {
				return $sent;
			}
		}

		$state = Reg_API::I()->get_category_mapping_state();
		if (is_wp_error($state)) {
			return $state;
		}

		$data = Reg_API::data($state);

		// Cache externalCategoryId => vendorCategoryId (pending selections sometimes only
		// give us one of the two).
		$map    = [];
		$hidden = [];
		foreach ((array) ($data['vendorCategories'] ?? []) as $vendor) {
			$map[(string) ($vendor['externalCategoryId'] ?? '')] = (int) ($vendor['id'] ?? 0);
			if ('Ignored' === ($vendor['decision'] ?? '')) {
				$hidden[] = (int) ($vendor['externalCategoryId'] ?? 0);
			}
		}
		update_option('regolith_category_mapping', $map, false);
		// WooCommerce term ids marked "Don't list", used to explain why a product isn't listed.
		update_option('regolith_hidden_categories', $hidden, false);

		return $data;
	}

	/**
	 * Active source categories that still need a decision.
	 */
	public static function count_undecided(array $data)
	{
		return (int) ($data['progress']['outstandingCategoryDecisionCount'] ?? 0);
	}

	public static function count_pending_selections(array $data)
	{
		return (int) ($data['progress']['outstandingProductSelectionCount'] ?? count((array) ($data['pendingProductSelections'] ?? [])));
	}

	// MARK: Suggestions

	/**
	 * Best Regolith category for a WooCommerce category name, or null.
	 * Exact match after normalising (case, punctuation, plural "s") first, then word overlap.
	 * Conservative on purpose: a wrong suggestion that gets clicked through is worse than none.
	 */
	public function suggest(string $name, array $regolith_categories)
	{
		$words = $this->words($name);
		if (! $words) {
			return null;
		}

		if (in_array(implode(' ', $words), ['uncategorized', 'uncategorised'], true)) {
			return self::IGNORE;
		}

		$best       = null;
		$best_score = 0.0;
		foreach ($regolith_categories as $category) {
			$candidate = $this->words((string) ($category['name'] ?? ''));
			if (! $candidate) {
				continue;
			}
			if ($candidate === $words) {
				return (int) $category['id'];
			}
			$shared = count(array_intersect($words, $candidate));
			$score  = $shared / count(array_unique(array_merge($words, $candidate)));
			if ($score > $best_score) {
				$best_score = $score;
				$best       = (int) $category['id'];
			}
		}

		return $best_score >= 0.5 ? $best : null;
	}

	private function words($text)
	{
		$text  = strtolower(remove_accents(html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
		$parts = preg_split('/[^a-z0-9]+/', str_replace('&', ' and ', $text), -1, PREG_SPLIT_NO_EMPTY);
		$parts = array_diff($parts, ['and', 'the', 'of', 'for']);
		$parts = array_map(static fn ($w) => strlen($w) > 3 ? preg_replace('/(ies)$/', 'y', preg_replace('/(?<!s)s$/', '', $w)) : $w, $parts);
		sort($parts);
		return array_values(array_unique($parts));
	}

	// MARK: Mapping Form

	/**
	 * The category table.
	 *
	 * @param array  $data    Mapping state (get_state()).
	 * @param string $context 'wizard' | 'settings'
	 */
	public function render_mapping(array $data, $context = 'settings')
	{
		$regolith = array_values(array_filter((array) ($data['regolithCategories'] ?? []), static fn ($c) => isset($c['id'], $c['name'])));
		usort($regolith, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));

		$vendor = array_values(array_filter((array) ($data['vendorCategories'] ?? []), static fn ($c) => ($c['isSourceActive'] ?? true)));
		$vendor = $this->sort_as_tree($vendor);

		if (! $vendor) {
			echo '<div class="reg-alert reg-alert--warn">No WooCommerce categories were found. Create at least one product category, then reload this page.</div>';
			return;
		}

		$total   = count($vendor);
		$decided = count(array_filter($vendor, static fn ($c) => in_array($c['decision'] ?? 'Undecided', ['Mapped', 'Ignored'], true)));
		?>
		<form class="reg-categories" data-reg-categories-form>
			<div class="reg-categories__toolbar">
				<div class="reg-progress-text"><strong data-reg-decided><?php echo (int) $decided; ?></strong> of <strong data-reg-total><?php echo (int) $total; ?></strong> categories decided</div>
				<button type="button" class="reg-btn reg-btn--ghost reg-btn--small" data-reg-ignore-rest>Don't list the remaining ones</button>
			</div>

			<table class="reg-table">
				<thead>
					<tr>
						<th>Your category</th>
						<th style="width:110px;">Products</th>
						<th>Show on Regolith as</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($vendor as $cat) :
						$vendor_id  = (int) ($cat['id'] ?? 0);
						$decision   = (string) ($cat['decision'] ?? 'Undecided');
						$current    = 'Mapped' === $decision ? (int) ($cat['internalCategoryId'] ?? 0) : ('Ignored' === $decision ? self::IGNORE : '');
						$suggestion = '' === $current ? $this->suggest((string) ($cat['name'] ?? ''), $regolith) : null;
						$value      = '' !== $current ? $current : ($suggestion ?? '');
						?>
						<tr class="<?php echo '' === $current ? 'is-undecided' : ''; ?>">
							<td>
								<span class="reg-tree-indent" style="--depth: <?php echo (int) ($cat['_depth'] ?? 0); ?>"></span>
								<strong><?php echo esc_html(html_entity_decode((string) ($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></strong>
								<?php if (null !== $suggestion) : ?><?php echo Reg_UI::pill('Suggested', 'info', 'We picked this by name, change it if it\'s wrong.'); ?><?php endif; ?>
							</td>
							<td class="reg-muted"><?php echo (int) ($cat['productCount'] ?? 0); ?></td>
							<td>
								<select class="reg-select" name="cat[<?php echo $vendor_id; ?>]" data-reg-category-select data-original="<?php echo esc_attr((string) $current); ?>">
									<option value="" <?php selected($value, ''); ?>>Choose…</option>
									<option value="<?php echo esc_attr(self::IGNORE); ?>" <?php selected((string) $value, self::IGNORE); ?>>Don't list on Regolith</option>
									<optgroup label="Regolith categories">
										<?php foreach ($regolith as $r) : ?>
											<option value="<?php echo (int) $r['id']; ?>" <?php selected((string) $value, (string) $r['id']); ?>><?php echo esc_html($r['name']); ?></option>
										<?php endforeach; ?>
									</optgroup>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="reg-muted">Products in a category marked "Don't list" aren't sent to Regolith. A product in several categories uses the one(s) that are listed.</p>
		</form>
		<?php
	}

	/**
	 * Order categories parent-first and tag each with its depth (_depth).
	 */
	private function sort_as_tree(array $categories): array
	{
		$children = [];
		$ids      = [];
		foreach ($categories as $cat) {
			$ids[(string) ($cat['externalCategoryId'] ?? '')] = true;
		}
		foreach ($categories as $cat) {
			$parent = (string) ($cat['parentExternalCategoryId'] ?? '');
			// Orphans (parent not in the list) go to the top level.
			$children[isset($ids[$parent]) ? $parent : ''][] = $cat;
		}

		$out  = [];
		$walk = function ($parent, $depth) use (&$walk, &$out, $children) {
			$level = $children[$parent] ?? [];
			usort($level, static fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
			foreach ($level as $cat) {
				$cat['_depth'] = $depth;
				$out[]         = $cat;
				$id            = (string) ($cat['externalCategoryId'] ?? '');
				if ('' !== $id && $depth < 20) {
					$walk($id, $depth + 1);
				}
			}
		};
		$walk('', 0);

		return $out;
	}

	// MARK: Selections Form

	/**
	 * Products in several categories that map to different Regolith categories.
	 *
	 * Row shape (sandbox, 2026-10-28):
	 * { externalProductId, name, eligibleCategories: [ { vendorCategoryId, vendorCategoryName, internalCategoryId, internalCategoryName } ] }
	 * Read defensively anyway (id / name / externalCategoryId fallbacks).
	 */
	public function render_selections(array $data)
	{
		$pending     = (array) ($data['pendingProductSelections'] ?? []);
		$outstanding = self::count_pending_selections($data);
		$lookup      = (array) get_option('regolith_category_mapping', []);

		if (! $pending) {
			echo $outstanding
				? '<div class="reg-alert reg-alert--warn">Regolith reports ' . (int) $outstanding . ' product(s) needing a choice but didn\'t list them. Reload in a minute.</div>'
				: '<div class="reg-alert reg-alert--ok">Every product has a clear category. Nothing to do here.</div>';
			return;
		}

		$all_options = [];
		$rows        = [];
		foreach ($pending as $item) {
			$options = [];
			foreach ((array) ($item['eligibleCategories'] ?? []) as $cat) {
				$vendor_id = (int) ($cat['vendorCategoryId'] ?? $cat['id'] ?? ($lookup[$cat['externalCategoryId'] ?? ''] ?? 0));
				if (! $vendor_id) {
					continue;
				}
				$options[$vendor_id] = [
					'yours'    => html_entity_decode((string) ($cat['vendorCategoryName'] ?? $cat['name'] ?? '#' . $vendor_id), ENT_QUOTES, 'UTF-8'),
					'regolith' => (string) ($cat['internalCategoryName'] ?? ''),
				];
				$all_options[$vendor_id] = $options[$vendor_id];
			}
			$rows[] = [
				'id'      => (string) ($item['externalProductId'] ?? ''),
				'name'    => (string) ($item['name'] ?? $item['externalProductId'] ?? ''),
				'options' => $options,
			];
		}
		?>
		<form class="reg-selections" data-reg-selections-form>
			<?php if (count($rows) > 1) : ?>
				<div class="reg-categories__toolbar">
					<label>Quick pick: use
						<select class="reg-select reg-select--inline" data-reg-bulk-selection>
							<option value="">…</option>
							<?php foreach ($all_options as $vendor_id => $opt) : ?>
								<option value="<?php echo (int) $vendor_id; ?>"><?php echo esc_html($opt['yours'] . ($opt['regolith'] ? ' → ' . $opt['regolith'] : '')); ?></option>
							<?php endforeach; ?>
						</select>
						for every product below that's in it
					</label>
				</div>
			<?php endif; ?>

			<?php foreach ($rows as $row) :
				$post_id = (int) preg_replace('/^product:/', '', $row['id']);
				$thumb   = $post_id ? get_the_post_thumbnail_url($post_id, 'thumbnail') : '';
				?>
				<fieldset class="reg-choice-card" data-reg-selection="<?php echo esc_attr($row['id']); ?>">
					<legend class="reg-choice-card__head">
						<?php if ($thumb) : ?><img src="<?php echo esc_url($thumb); ?>" alt=""><?php endif; ?>
						<span><strong><?php echo esc_html($row['name']); ?></strong>
							<?php if ($post_id && get_post($post_id)) : ?><a class="reg-muted" href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank">view product</a><?php endif; ?></span>
					</legend>
					<div class="reg-choice-card__options">
						<?php foreach ($row['options'] as $vendor_id => $opt) : ?>
							<label class="reg-radio">
								<input type="radio" name="sel[<?php echo esc_attr($row['id']); ?>]" value="<?php echo (int) $vendor_id; ?>">
								<span><strong><?php echo esc_html($opt['regolith'] ?: $opt['yours']); ?></strong>
									<span class="reg-muted">from your "<?php echo esc_html($opt['yours']); ?>" category</span></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endforeach; ?>
		</form>
		<?php
	}

	// MARK: AJAX

	private function guard()
	{
		check_ajax_referer('regolith_admin');
		if (! current_user_can(Reg_UI::CAPABILITY)) {
			wp_send_json_error('Permission denied.', 403);
		}
	}

	/**
	 * Save map / don't-list decisions. Only changed rows are sent (each change recategorizes
	 * linked items on Regolith's side, no need to make it redo the unchanged ones).
	 * Answers with what's still undecided so the UI knows whether to move on.
	 */
	public function ajax_save_categories()
	{
		$this->guard();

		$posted    = (array) wp_unslash($_POST['cat'] ?? []);
		$originals = (array) wp_unslash($_POST['original'] ?? []);
		$mappings  = [];

		foreach ($posted as $vendor_id => $value) {
			$vendor_id = absint($vendor_id);
			$value     = sanitize_text_field((string) $value);
			if (! $vendor_id || '' === $value || $value === sanitize_text_field((string) ($originals[$vendor_id] ?? ''))) {
				continue;
			}
			$mappings[] = self::IGNORE === $value
				? ['vendorCategoryId' => $vendor_id, 'decision' => 'Ignored', 'internalCategoryId' => null]
				: ['vendorCategoryId' => $vendor_id, 'decision' => 'Mapped', 'internalCategoryId' => absint($value)];
		}

		if ($mappings) {
			$response = Reg_API::I()->save_category_mappings($mappings);
			if (is_wp_error($response)) {
				$msg = Reg_Messages::from_error($response);
				wp_send_json_error($msg['title'] . '. ' . $msg['help']);
			}
		}

		$data = $this->get_state();
		if (is_wp_error($data)) {
			$msg = Reg_Messages::from_error($data);
			wp_send_json_error($msg['title'] . '. ' . $msg['help']);
		}

		// After go-live a mapping change can move products between categories: memberships
		// don't change, but the products' rows resolve differently, so re-send them.
		if ($mappings && Reg_Wizard::I()->is_complete()) {
			Reg_Onboarding::I()->mark_dirty('memberships');
		}

		wp_send_json_success([
			'saved'      => count($mappings),
			'undecided'  => self::count_undecided($data),
			'selections' => self::count_pending_selections($data),
		]);
	}

	public function ajax_save_selections()
	{
		$this->guard();

		$selections = [];
		foreach ((array) wp_unslash($_POST['sel'] ?? []) as $external_product_id => $vendor_id) {
			$external_product_id = sanitize_text_field((string) $external_product_id);
			$vendor_id           = absint($vendor_id);
			if ($vendor_id && preg_match('/^product:\d+$/', $external_product_id)) {
				$selections[] = ['externalProductId' => $external_product_id, 'vendorCategoryId' => $vendor_id];
			}
		}

		if (! $selections) {
			wp_send_json_error('Pick a category for at least one product.');
		}

		$response = Reg_API::I()->save_category_selections($selections);
		if (is_wp_error($response)) {
			$msg = Reg_Messages::from_error($response);
			wp_send_json_error($msg['title'] . '. ' . $msg['help']);
		}

		// Their rows resolve differently now. Before go-live the first Full sync covers them.
		if (Reg_Wizard::I()->is_complete()) {
			foreach ($selections as $selection) {
				Reg_Sync_Queue::I()->enqueue_product_and_children((int) substr($selection['externalProductId'], 8), 'update');
			}
		}

		$data = $this->get_state();

		wp_send_json_success([
			'saved'      => count($selections),
			'selections' => is_wp_error($data) ? 0 : self::count_pending_selections($data),
		]);
	}
}
