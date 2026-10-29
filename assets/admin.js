/**
 * Regolith admin screens.
 *
 * Plain jQuery, no build step. Everything is delegated from document, so it keeps working when
 * a panel's HTML is swapped by a refresh. Every call goes to admin-ajax with the shared nonce
 * (regolithAdmin.nonce); handlers live in the PHP classes named next to each block.
 *
 * Blocks:
 *   Helpers
 *   Connect           (Reg_Wizard::ajax_connect, also Settings → Connection)
 *   Categories        (Reg_Category_Mapper, + membership snapshot via Reg_Wizard)
 *   Selections        (Reg_Category_Mapper)
 *   Product details   (Reg_Field_Mapper)
 *   Go live + Live    (Reg_Wizard)
 *   Overview          (Reg_Dashboard)
 *   Generic actions   (data-reg-action buttons)
 *   Products list     (Reg_Dashboard row action)
 */
(function ($) {
	'use strict';

	var cfg = window.regolithAdmin || {};

	// MARK: Helpers

	function post(action, data) {
		if (typeof data === 'string') {
			return $.post(cfg.ajaxUrl, 'action=' + encodeURIComponent(action) + '&_ajax_nonce=' + encodeURIComponent(cfg.nonce) + (data ? '&' + data : ''));
		}
		return $.post(cfg.ajaxUrl, $.extend({ action: action, _ajax_nonce: cfg.nonce }, data || {}));
	}

	function errorText(res, fallback) {
		if (res && res.data && typeof res.data === 'string') {
			return res.data;
		}
		if (res && res.data && res.data.message) {
			return res.data.message;
		}
		return fallback || 'Something went wrong. Please try again.';
	}

	/** Status line next to the main button of a step / form. */
	function status($scope, text, tone) {
		var $s = $scope.find('[data-reg-status]').first();
		if (!$s.length) {
			$s = $('[data-reg-status]').first();
		}
		$s.removeClass('is-error is-ok').addClass(tone ? 'is-' + tone : '').text(text || '');
	}

	var toastTimer;
	function toast(text, isError) {
		$('.reg-toast').remove();
		var $t = $('<div class="reg-toast" role="status"></div>').toggleClass('is-error', !!isError).text(text);
		$('body').append($t);
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () {
			$t.fadeOut(200, function () { $t.remove(); });
		}, isError ? 8000 : 4000);
	}

	function busy($btn, on) {
		$btn.toggleClass('is-busy', !!on).prop('disabled', !!on);
	}

	function goToStep(step) {
		window.location.href = cfg.urls.setup + '&step=' + encodeURIComponent(step);
	}

	// Show / hide a password field.
	$(document).on('click', '[data-reg-toggle-secret]', function (e) {
		e.preventDefault();
		var $input = $($(this).data('reg-toggle-secret'));
		var show = $input.attr('type') === 'password';
		$input.attr('type', show ? 'text' : 'password');
		$(this).text(show ? 'Hide' : 'Show');
	});

	$(document).on('click', '[data-reg-go]', function (e) {
		e.preventDefault();
		goToStep($(this).data('reg-go'));
	});

	// MARK: Connect

	$(document).on('click', '[data-reg-show-connect]', function (e) {
		e.preventDefault();
		$('[data-reg-connect-form]').prop('hidden', false).find('input').first().trigger('focus');
		$(this).closest('p').remove();
		// The step's main button becomes "Connect".
		$('[data-reg-wizard] .reg-wizard__nav .reg-btn--primary').removeAttr('data-reg-go').attr('data-reg-connect', '').text('Connect');
	});

	$(document).on('click', '[data-reg-connect]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $form = $('[data-reg-connect-form]');
		var stay = $form.is('[data-reg-stay]');
		var $scope = $btn.closest('.reg-card, .reg-wizard__card');

		busy($btn, true);
		status($scope, 'Checking your key with Regolith…');

		post('regolith_wizard_connect', {
			api_key: $form.find('[name="api_key"]').val() || '',
			api_url: $form.find('[name="api_url"]').val() || ''
		}).done(function (res) {
			if (!res.success) {
				status($scope, errorText(res), 'error');
				busy($btn, false);
				return;
			}
			status($scope, 'Connected.', 'ok');
			setTimeout(function () {
				if (stay) {
					window.location.reload();
				} else {
					window.location.href = res.data.next;
				}
			}, 600);
		}).fail(function () {
			status($scope, 'Couldn\'t reach your own site. Check your connection and try again.', 'error');
			busy($btn, false);
		});
	});

	// MARK: Categories

	function updateCategoryCount() {
		var $form = $('[data-reg-categories-form]');
		var $selects = $form.find('[data-reg-category-select]');
		var decided = $selects.filter(function () { return $(this).val() !== ''; }).length;
		$form.find('[data-reg-decided]').text(decided);
		$selects.each(function () {
			$(this).closest('tr').toggleClass('is-undecided', $(this).val() === '').removeClass('is-flagged');
		});
		return $selects.length - decided;
	}

	$(document).on('change', '[data-reg-category-select]', updateCategoryCount);

	$(document).on('click', '[data-reg-ignore-rest]', function (e) {
		e.preventDefault();
		var changed = 0;
		$('[data-reg-category-select]').each(function () {
			if ($(this).val() === '') {
				$(this).val('__ignored__');
				changed++;
			}
		});
		updateCategoryCount();
		toast(changed ? changed + ' categories set to "Don\'t list". Remember to save.' : 'Every category already has a decision.');
	});

	$(document).on('click', '[data-reg-save-categories]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var stay = $btn.is('[data-reg-stay]');
		var $scope = $btn.closest('.reg-card, .reg-wizard__card');
		var undecided = updateCategoryCount();

		// The wizard only moves on when everything is decided (safe go-live).
		if (!stay && undecided > 0) {
			$('[data-reg-category-select]').filter(function () { return $(this).val() === ''; }).closest('tr').addClass('is-flagged');
			status($scope, undecided + (undecided === 1 ? ' category still needs' : ' categories still need') + ' a choice (highlighted).', 'error');
			$('tr.is-flagged').first()[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
			return;
		}

		var data = {};
		$('[data-reg-category-select]').each(function () {
			data[this.name] = $(this).val();
			data['original' + this.name.substring(3)] = $(this).data('original');
		});

		busy($btn, true);
		status($scope, 'Saving…');

		post('regolith_save_categories', data).done(function (res) {
			if (!res.success) {
				status($scope, errorText(res), 'error');
				busy($btn, false);
				return;
			}
			if (stay) {
				status($scope, 'Saved.', 'ok');
				setTimeout(function () { window.location.reload(); }, 700);
				return;
			}
			if (res.data.undecided > 0) {
				status($scope, 'Regolith still sees ' + res.data.undecided + ' undecided categories. Reload the page and check.', 'error');
				busy($btn, false);
				return;
			}
			sendProductList($btn, $scope);
		}).fail(function () {
			status($scope, 'Saving failed, please try again.', 'error');
			busy($btn, false);
		});
	});

	/**
	 * Wizard: send the product list (membership snapshot) with a progress overlay.
	 * The server works in ~15s slices, we keep asking until it says complete.
	 */
	function sendProductList($btn, $scope) {
		var $overlay = $('[data-reg-overlay]').prop('hidden', false);
		$overlay.find('[data-reg-overlay-title]').text('Sending your product list to Regolith…');

		function step() {
			post('regolith_wizard_memberships').done(function (res) {
				if (!res.success) {
					$overlay.prop('hidden', true);
					status($scope, errorText(res), 'error');
					busy($btn, false);
					return;
				}
				var d = res.data;
				var pct = d.total ? Math.round(100 * d.sent / d.total) : 100;
				$overlay.find('[data-reg-overlay-progress]').val(pct);
				$overlay.find('[data-reg-overlay-text]').text(d.sent + ' of ' + d.total + ' products');

				if (d.status === 'complete') {
					$overlay.find('[data-reg-overlay-title]').text('Done!');
					window.location.href = d.next;
				} else if (d.status === 'running') {
					step();
				} else if (d.status === 'wait') {
					$overlay.find('[data-reg-overlay-text]').text('Regolith is busy, continuing in ' + d.retry_in + 's…');
					setTimeout(step, Math.max(1, d.retry_in) * 1000);
				} else {
					$overlay.prop('hidden', true);
					status($scope, d.message || 'Sending the product list failed.', 'error');
					busy($btn, false);
				}
			}).fail(function () {
				// Network blip: the job is resumable, just try again shortly.
				setTimeout(step, 4000);
			});
		}
		step();
	}

	// MARK: Selections

	$(document).on('change', '[data-reg-bulk-selection]', function () {
		var val = $(this).val();
		if (!val) {
			return;
		}
		var count = 0;
		$('[data-reg-selection]').each(function () {
			var $radio = $(this).find('input[type=radio][value="' + val + '"]');
			if ($radio.length) {
				$radio.prop('checked', true);
				$(this).removeClass('is-flagged');
				count++;
			}
		});
		toast(count + ' product' + (count === 1 ? '' : 's') + ' set. Remember to save.');
	});

	$(document).on('change', '[data-reg-selection] input', function () {
		$(this).closest('[data-reg-selection]').removeClass('is-flagged');
	});

	$(document).on('click', '[data-reg-save-selections]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var stay = $btn.is('[data-reg-stay]');
		var $scope = $btn.closest('.reg-card, .reg-wizard__card');
		var $missing = $('[data-reg-selection]').filter(function () { return !$(this).find('input:checked').length; });

		if (!stay && $missing.length) {
			$missing.addClass('is-flagged');
			status($scope, $missing.length + ' product' + ($missing.length === 1 ? '' : 's') + ' still need a choice (highlighted).', 'error');
			$missing.first()[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
			return;
		}

		busy($btn, true);
		status($scope, 'Saving…');

		post('regolith_save_selections', $('[data-reg-selections-form]').serialize()).done(function (res) {
			if (!res.success) {
				status($scope, errorText(res), 'error');
				busy($btn, false);
				return;
			}
			if (stay || res.data.selections > 0) {
				status($scope, res.data.selections > 0 ? res.data.selections + ' still open, reloading…' : 'Saved.', 'ok');
				setTimeout(function () { window.location.reload(); }, 800);
				return;
			}
			goToStep('details');
		}).fail(function () {
			status($scope, 'Saving failed, please try again.', 'error');
			busy($btn, false);
		});
	});

	// MARK: Product details

	var sourcesById = null;

	function sourceLabel(s) {
		return s.label + ' · ' + s.type + ' · ' + s.count + ' products' + (s.sample ? ' · e.g. ' + s.sample : '');
	}

	/**
	 * Fill a source <select> from window.regolithSources the first time it's used.
	 * Grouped by type, biggest coverage first inside each group.
	 */
	function fillSourceSelect(select) {
		var $sel = $(select);
		if ($sel.data('filled')) {
			return;
		}
		var sources = window.regolithSources || [];
		var current = $sel.val();
		var groups = {};
		sources.forEach(function (s) {
			(groups[s.type] = groups[s.type] || []).push(s);
		});

		var $first = $sel.find('option').first().detach();
		$sel.empty().append($first);
		Object.keys(groups).forEach(function (type) {
			var $g = $('<optgroup>').attr('label', type);
			groups[type].sort(function (a, b) { return b.count - a.count; }).forEach(function (s) {
				$g.append($('<option>').val(s.id).text(sourceLabel(s)));
			});
			$sel.append($g);
		});
		if (current && !$sel.find('option[value="' + current.replace(/"/g, '') + '"]').length) {
			$sel.append($('<option>').val(current).text(current + ' (not found on your store any more)'));
		}
		$sel.val(current).data('filled', true);
	}

	$(document).on('mousedown focus keydown', '[data-reg-source-select]', function () {
		fillSourceSelect(this);
	});

	function updateGroupCount($group) {
		var rows = $group.find('[data-reg-field]');
		var filled = rows.filter(function () { return $(this).find('[data-slot="0"]').val() !== ''; }).length;
		$group.find('[data-reg-group-count]').text(filled + ' of ' + rows.length);
	}

	$(document).on('change', '[data-reg-source-select]', function () {
		var $row = $(this).closest('[data-reg-field]');
		var $primary = $row.find('[data-slot="0"]');
		var $fallback = $row.find('[data-slot="1"]');
		if ($primary.val() === '') {
			$fallback.val('').prop('hidden', true);
		} else {
			$fallback.prop('hidden', false);
		}
		$row.find('.reg-pill--info').remove(); // a manual choice isn't a suggestion any more
		updateGroupCount($row.closest('.reg-group'));
		schedulePreview();
	});

	$(document).on('input', '[data-reg-field-search]', function () {
		var q = $.trim($(this).val()).toLowerCase();
		$('[data-reg-field]').each(function () {
			$(this).toggleClass('is-hidden', q !== '' && $(this).data('search').indexOf(q) === -1);
		});
		$('.reg-group').each(function () {
			var any = $(this).find('[data-reg-field]:not(.is-hidden)').length > 0;
			$(this).toggle(any);
			if (q !== '' && any) {
				this.open = true;
			}
		});
	});

	var previewTimer;
	function schedulePreview() {
		clearTimeout(previewTimer);
		previewTimer = setTimeout(loadPreview, 500);
	}

	function loadPreview() {
		var id = $('[data-reg-preview-product]').val();
		var $out = $('[data-reg-preview]');
		if (!id) {
			return;
		}
		$out.css('opacity', 0.5);
		post('regolith_preview_product', 'product_id=' + encodeURIComponent(id) + '&' + $('[data-reg-fields-form]').serialize())
			.done(function (res) {
				$out.html(res.success ? res.data.html : '<p class="reg-text-warn"></p>');
				if (!res.success) {
					$out.find('p').text(errorText(res));
				}
			})
			.always(function () { $out.css('opacity', 1); });
	}

	$(document).on('change', '[data-reg-preview-product]', loadPreview);

	$(document).on('click', '[data-reg-rescan]', function (e) {
		e.preventDefault();
		$(this).text('Scanning…');
		post('regolith_rescan_fields').always(function () { window.location.reload(); });
	});

	$(document).on('click', '[data-reg-save-fields]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var stay = $btn.is('[data-reg-stay]');
		var $scope = $btn.closest('.reg-card, .reg-wizard__card');

		busy($btn, true);
		status($scope, 'Saving…');

		post('regolith_save_fields', $('[data-reg-fields-form]').serialize()).done(function (res) {
			if (!res.success) {
				status($scope, errorText(res), 'error');
				busy($btn, false);
				return;
			}
			if (stay) {
				status($scope, res.data.message, 'ok');
				busy($btn, false);
				return;
			}
			goToStep('review');
		}).fail(function () {
			status($scope, 'Saving failed, please try again.', 'error');
			busy($btn, false);
		});
	});

	// MARK: Go live

	$(document).on('click', '[data-reg-go-live]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $scope = $btn.closest('.reg-wizard__card');
		busy($btn, true);
		status($scope, 'Finishing setup with Regolith…');

		post('regolith_wizard_go_live').done(function (res) {
			if (!res.success) {
				status($scope, errorText(res), 'error');
				busy($btn, false);
				return;
			}
			window.location.href = res.data.next;
		}).fail(function () {
			status($scope, 'Couldn\'t finish setup, please try again.', 'error');
			busy($btn, false);
		});
	});

	// Live progress of the first Full sync.
	function pollLive() {
		var $live = $('[data-reg-live]');
		if (!$live.length) {
			return;
		}
		post('regolith_wizard_progress').done(function (res) {
			if (!res.success) {
				setTimeout(pollLive, 5000);
				return;
			}
			var d = res.data;
			if (!d.done) {
				$live.find('[data-reg-live-progress]').val(d.percent);
				$live.find('[data-reg-live-text]').text(d.text);
				setTimeout(pollLive, 1200);
				return;
			}
			var s = d.stats || { live: 0, unlisted: 0, attention: 0 };
			var $stats = $live.find('[data-reg-live-stats]').empty();
			[[s.live, 'products live on Regolith', ''], [s.unlisted, 'not listed yet (out of stock, no price…)', 'reg-stat--muted'], [s.attention, 'need your attention', s.attention ? '' : 'reg-stat--muted']].forEach(function (x) {
				$stats.append($('<div class="reg-stat"></div>').addClass(x[2]).append($('<span class="reg-stat__num"></span>').text(x[0])).append($('<span></span>').text(x[1])));
			});
			$live.find('[data-reg-live-attention]').prop('hidden', !s.attention);
			if (d.failed) {
				$live.find('[data-reg-live-done] h1').text('Setup is done, but the first sync stopped');
				$live.find('[data-reg-live-done] .reg-lead').text(d.text);
			}
			$live.find('[data-reg-live-running]').prop('hidden', true);
			$live.find('[data-reg-live-done]').prop('hidden', false);
		}).fail(function () {
			setTimeout(pollLive, 5000);
		});
	}

	// MARK: Overview

	function pollOverview() {
		var $panel = $('[data-reg-overview]');
		if (!$panel.length) {
			return;
		}
		if (document.hidden) {
			setTimeout(pollOverview, 6000);
			return;
		}
		post('regolith_overview_refresh').done(function (res) {
			if (res.success) {
				$panel.html(res.data.html);
			}
		}).always(function () {
			// Faster while something is being sent.
			setTimeout(pollOverview, $panel.find('.reg-health--info').length ? 2500 : 15000);
		});
	}

	// MARK: Generic actions

	$(document).on('click', '[data-reg-action]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		if ($btn.data('confirm') && !window.confirm($btn.data('confirm'))) {
			return;
		}
		busy($btn, true);
		post($btn.data('reg-action'), $btn.data('args') || {}).done(function (res) {
			toast(res.success ? (res.data && res.data.message) || 'Done.' : errorText(res), !res.success);
			if (res.success && res.data) {
				if (res.data.redirect) {
					window.location.href = res.data.redirect;
					return;
				}
				if (res.data.html && $('[data-reg-overview]').length) {
					$('[data-reg-overview]').html(res.data.html);
				}
				if ($btn.data('reg-action') === 'regolith_retry_group') {
					$btn.closest('.reg-issue').css('opacity', 0.5);
				}
			}
		}).fail(function () {
			toast('Request failed, please try again.', true);
		}).always(function () {
			busy($btn, false);
		});
	});

	// MARK: Updates (Settings → Advanced)

	$(document).on('click', '[data-reg-check-updates]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $card = $btn.closest('.reg-card');
		busy($btn, true);
		status($card, 'Asking GitHub…');
		post('regolith_check_updates').done(function (res) {
			if (res.success) {
				$card.replaceWith(res.data.html);
			} else {
				status($card, errorText(res), 'error');
				busy($btn, false);
			}
		}).fail(function () {
			status($card, 'Request failed.', 'error');
			busy($btn, false);
		});
	});

	// MARK: Products list

	$(document).on('click', '[data-reg-product-sync]', function (e) {
		e.preventDefault();
		var id = $(this).data('reg-product-sync');
		var $status = $('#reg-status-' + id);
		var $link = $(this).text('Syncing…');
		post('regolith_product_sync', { product_id: id }).done(function (res) {
			if (res.success) {
				$status.html(res.data.html);
				$link.text('Sync to Regolith');
				toast(res.data.reason);
			} else {
				$link.text('Sync to Regolith');
				toast(errorText(res), true);
			}
		}).fail(function () {
			$link.text('Sync to Regolith');
			toast('Request failed.', true);
		});
	});

	// MARK: Boot

	$(function () {
		if ($('[data-reg-categories-form]').length) {
			updateCategoryCount();
		}
		pollLive();
		setTimeout(pollOverview, 4000);
	});
})(jQuery);
