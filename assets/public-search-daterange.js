/**
 * Date range picker (https://www.daterangepicker.com/) + Moment.js for BEC enhanced search.
 */
(function ($) {
	'use strict';

	function getCfg() {
		return typeof window.becSearchForm === 'object' && window.becSearchForm ? window.becSearchForm : {};
	}

	/**
	 * @param {HTMLFormElement} form
	 * @returns {Array<{from: string, to: string}>}
	 */
	function getUnavailableRanges(form) {
		var raw = form.getAttribute('data-bec-unavailable-ranges') || '';
		if (!raw) {
			return [];
		}
		try {
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (err) {
			return [];
		}
	}

	/**
	 * @param {HTMLFormElement} form
	 * @returns {Array<{from: string, to: string}>}
	 */
	function getInvalidCheckinRanges(form) {
		var raw = form.getAttribute('data-bec-invalid-checkin-ranges') || '';
		if (!raw) {
			return [];
		}
		try {
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (err) {
			return [];
		}
	}

	/**
	 * @param {HTMLFormElement} form
	 * @returns {number}
	 */
	function getMinNights(form) {
		var raw = form.getAttribute('data-bec-min-nights') || '';
		if (raw !== '') {
			var fromAttr = parseInt(raw, 10);
			if (!isNaN(fromAttr) && fromAttr > 0) {
				return fromAttr;
			}
		}

		var cfg = getCfg();
		if (typeof cfg.minNights === 'number' && cfg.minNights > 0) {
			return cfg.minNights;
		}

		return 1;
	}

	/**
	 * @param {import('moment').Moment} m
	 * @param {Array<{from: string, to: string}>} ranges
	 * @returns {boolean}
	 */
	function isDateInRanges(m, ranges) {
		if (!m || !m.isValid() || !ranges || !ranges.length) {
			return false;
		}
		for (var i = 0; i < ranges.length; i++) {
			var r = ranges[i];
			if (!r || !r.from || !r.to) {
				continue;
			}
			var from = moment(r.from, 'YYYY-MM-DD', true);
			var to = moment(r.to, 'YYYY-MM-DD', true);
			if (!from.isValid() || !to.isValid()) {
				continue;
			}
			if (m.isBetween(from, to, 'day', '[]')) {
				return true;
			}
		}
		return false;
	}

	function isDateInUnavailableRanges(m, ranges) {
		return isDateInRanges(m, ranges);
	}

	/**
	 * @param {import('moment').Moment} start
	 * @param {import('moment').Moment} end
	 * @param {Array<{from: string, to: string}>} unavailableRanges
	 * @returns {boolean}
	 */
	function hasInventoryUnavailableNightBetween(start, end, unavailableRanges) {
		if (!start || !end || !start.isValid() || !end.isValid() || !unavailableRanges.length) {
			return false;
		}
		if (!end.isAfter(start, 'day')) {
			return false;
		}

		var cur = start.clone();
		while (cur.isBefore(end, 'day')) {
			if (isDateInUnavailableRanges(cur, unavailableRanges)) {
				return true;
			}
			cur.add(1, 'day');
		}

		return false;
	}

	/**
	 * Daterangepicker sets endDate to null after the first click while selecting checkout.
	 *
	 * @param {object|null|undefined} picker
	 * @returns {boolean}
	 */
	function isPickingCheckout(picker) {
		return !!(
			picker &&
			picker.startDate &&
			picker.startDate.isValid &&
			picker.startDate.isValid() &&
			picker.endDate === null
		);
	}

	/**
	 * @param {HTMLFormElement} form
	 * @returns {import('moment').Moment|null}
	 */
	function resolveMaxSelectable(form) {
		var horizonTo = form.getAttribute('data-bec-availability-horizon-to') || '';
		if (horizonTo) {
			var fromAttr = moment(horizonTo, 'YYYY-MM-DD', true);
			if (fromAttr.isValid()) {
				return fromAttr;
			}
		}

		var cfg = getCfg();
		var maxDays = parseInt(cfg.maxDateFromToday, 10);
		if (!isNaN(maxDays) && maxDays > 0) {
			return moment().startOf('day').add(maxDays, 'days');
		}

		return null;
	}

	/**
	 * @param {HTMLFormElement} form
	 * @returns {'auto'|'up'|'down'}
	 */
	function getDaterangeDrops(form) {
		var raw = form.getAttribute('data-bec-popover-placement') || 'auto';
		raw = String(raw).toLowerCase().trim();
		if (raw === 'top') {
			return 'up';
		}
		if (raw === 'bottom') {
			return 'down';
		}
		return 'auto';
	}

	/**
	 * Bubble to native listeners on ancestors (e.g. booking summary’s root). jQuery’s
	 * .trigger("change") does not always do that for handlers added with addEventListener.
	 */
	function getDaterangeDisplayFormat(form) {
		var raw = form.getAttribute('data-bec-daterange-format') || '';
		raw = String(raw).trim();
		if (raw !== '') {
			return raw;
		}
		var cfg = getCfg();
		return cfg.daterangeFormat || 'D MMM YYYY';
	}

	function dispatchBecNativeInputChange($el) {
		var el = $el && $el[0];
		if (!el) {
			return;
		}
		try {
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		} catch (err) {}
	}

	function updateSplit($wrap, start, end) {
		var $in = $wrap.find('[data-bec-part="day-in"]');
		var $imy = $wrap.find('[data-bec-part="my-in"]');
		var $idow = $wrap.find('[data-bec-part="dow-in"]');
		var $out = $wrap.find('[data-bec-part="day-out"]');
		var $omy = $wrap.find('[data-bec-part="my-out"]');
		var $odow = $wrap.find('[data-bec-part="dow-out"]');
		var cfg = getCfg();
		var ph = cfg.datePlaceholder || '—';

		if (!start || !end || !start.isValid() || !end.isValid()) {
			$in.text(ph);
			$imy.text('');
			$idow.text('');
			$out.text(ph);
			$omy.text('');
			$odow.text('');
			return;
		}

		$in.text(start.format('D MMMM'));
		$imy.text('');
		$idow.text('');
		$out.text(end.format('D MMMM'));
		$omy.text('');
		$odow.text('');
	}

	function initDaterange(form) {
		var $form = $(form);
		var $wrap = $form.find('[data-bec-daterange]');
		if (!$wrap.length) {
			return;
		}

		var $btn = $wrap.find('.bec-search-form__date-split');
		var $inCheckin = $form.find('input[name="bec_checkin"]');
		var $inCheckout = $form.find('input[name="bec_checkout"]');

		if (!$btn.length) {
			return;
		}

		var wrap = form.closest('.bec-search-form-wrap--enhanced');
		var backdrop = wrap ? wrap.querySelector('.bec-search-form__backdrop') : null;
		var mqDrawer = window.matchMedia('(max-width: 639px)');

		function isGuestPanelOpen() {
			var gt = form.querySelector('.bec-search-form__control--guests .bec-search-form__trigger');
			if (!gt) {
				return false;
			}
			var panelId = gt.getAttribute('aria-controls');
			var panel = panelId ? document.getElementById(panelId) : null;
			if (
				panel instanceof HTMLElement &&
				!panel.hidden &&
				panel.classList.contains('bec-search-form__panel--open')
			) {
				return true;
			}
			return gt.getAttribute('aria-expanded') === 'true';
		}

		function hideMobileOverlay() {
			if (!wrap || !backdrop || !mqDrawer.matches) {
				return;
			}
			backdrop.hidden = true;
			backdrop.setAttribute('aria-hidden', 'true');
			wrap.classList.remove('bec-search-form-wrap--popover-open');
			document.body.style.overflow = '';
		}

		function syncBackdropWithDaterange(showing) {
			if (!wrap || !backdrop || !mqDrawer.matches) {
				return;
			}
			if (showing) {
				backdrop.hidden = false;
				backdrop.setAttribute('aria-hidden', 'false');
				wrap.classList.add('bec-search-form-wrap--popover-open');
				document.body.style.overflow = 'hidden';
			} else if (!isGuestPanelOpen()) {
				hideMobileOverlay();
			}
		}

		form.addEventListener('bec:search-overlay-closed', hideMobileOverlay);

		var cfg = getCfg();
		var loc = cfg.momentLocale || 'en';
		if (typeof moment !== 'undefined') {
			moment.locale(loc);
		}

		var ld = typeof moment !== 'undefined' ? moment.localeData() : null;
		var daysOfWeek = ld ? ld.weekdaysMin() : ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
		var monthNames = ld ? ld.months() : [];
		var firstDay = typeof cfg.firstDayOfWeek === 'number' ? cfg.firstDayOfWeek : 1;

		var ci = $inCheckin.val();
		var co = $inCheckout.val();
		var start = ci ? moment(ci, 'YYYY-MM-DD', true) : null;
		var end = co ? moment(co, 'YYYY-MM-DD', true) : null;

		if (!start || !start.isValid()) {
			start = moment().startOf('day');
		}
		if (!end || !end.isValid()) {
			end = start.clone().add(1, 'day');
		}
		if (end.isSameOrBefore(start, 'day')) {
			end = start.clone().add(1, 'day');
		}

		var maxNights = typeof cfg.maxNights === 'number' ? cfg.maxNights : 365;
		var minToday = cfg.minDateToday !== false;

		var drpOpts = {
			startDate: start,
			endDate: end,
			autoApply: false,
			autoUpdateInput: false,
			alwaysShowCalendars: true,
			linkedCalendars: true,
			showDropdowns: false,
			showCustomRangeLabel: false,
			opens: 'center',
			drops: getDaterangeDrops(form),
			parentEl: 'body',
			maxSpan: { days: maxNights },
			locale: {
				format: getDaterangeDisplayFormat(form),
				separator:
					cfg.dateRangeSeparator !== undefined && cfg.dateRangeSeparator !== ''
						? cfg.dateRangeSeparator
						: ' – ',
				applyLabel: cfg.applyLabel || 'Apply',
				cancelLabel: cfg.cancelLabel || 'Cancel',
				fromLabel: cfg.checkinLabel || 'Check-in',
				toLabel: cfg.checkoutLabel || 'Check-out',
				customRangeLabel: cfg.customRangeLabel || 'Custom',
				daysOfWeek: daysOfWeek,
				monthNames: monthNames,
				firstDay: firstDay,
			},
		};

		if (minToday) {
			drpOpts.minDate = moment().startOf('day');
		}

		var maxSelectable = resolveMaxSelectable(form);
		if (maxSelectable) {
			drpOpts.maxDate = maxSelectable;
		}

		var unavailableRanges = getUnavailableRanges(form);
		var invalidCheckinRanges = getInvalidCheckinRanges(form);
		var minNights = getMinNights(form);
		var calendarHintsActive =
			form.getAttribute('data-bec-calendar-availability') === '1' ||
			unavailableRanges.length > 0 ||
			invalidCheckinRanges.length > 0 ||
			minNights > 1;

		if (maxSelectable || calendarHintsActive) {
			drpOpts.isInvalidDate = function (m) {
				if (maxSelectable && m.isAfter(maxSelectable, 'day')) {
					return true;
				}
				if (drpOpts.minDate && m.isBefore(drpOpts.minDate, 'day')) {
					return true;
				}

				var picker = $btn.data('daterangepicker');
				if (isPickingCheckout(picker) && picker.startDate) {
					if (!m.isAfter(picker.startDate, 'day')) {
						return true;
					}
					var nights = m.diff(picker.startDate, 'days');
					if (minNights > 1 && nights < minNights) {
						return true;
					}
					if (hasInventoryUnavailableNightBetween(picker.startDate, m, unavailableRanges)) {
						return true;
					}
					return false;
				}

				if (isDateInUnavailableRanges(m, unavailableRanges)) {
					return true;
				}
				if (invalidCheckinRanges.length && isDateInRanges(m, invalidCheckinRanges)) {
					return true;
				}
				return false;
			};
		}

		$btn.daterangepicker(drpOpts);

		var drp = $btn.data('daterangepicker');

		if (drp && calendarHintsActive) {
			drp.container.on('click.daterangepicker.becMinStay', 'td.available', function () {
				window.setTimeout(function () {
					if (drp && typeof drp.updateView === 'function') {
						drp.updateView();
					}
				}, 0);
			});
		}

		if (drp && maxSelectable) {
			drp.maxDate = maxSelectable;
			drp.maxYear = maxSelectable.year();
		}

		/**
		 * Wrap calendar panes so mobile CSS can scroll only the calendars and keep .drp-buttons pinned.
		 * Safe to call once; no-op if a wrap already exists or markup is unexpected.
		 */
		function ensureDrpScrollWrap() {
			if (!drp || !drp.container || !drp.container.length) {
				return;
			}
			var $root = drp.container;
			if ($root.find('.bec-drp-scroll').length) {
				return;
			}
			var $left = $root.find('.drp-calendar.left');
			if (!$left.length) {
				return;
			}
			var $right = $root.find('.drp-calendar.right');
			var $scroll = $('<div class="bec-drp-scroll" />');
			$left.first().before($scroll);
			$scroll.append($left);
			if ($right.length) {
				$scroll.append($right);
			}
		}
		ensureDrpScrollWrap();

		(function patchDrpViewportAutoDrops() {
			if (!drp || typeof drp.move !== 'function') {
				return;
			}
			var placementMode = getDaterangeDrops(form);
			if (placementMode !== 'auto') {
				return;
			}
			var origMove = drp.move.bind(drp);
			var edgeMargin = 8;

			function resolveViewportDrops() {
				if (mqDrawer.matches) {
					return;
				}
				var trigger = $btn[0];
				if (!trigger) {
					return;
				}
				var rect = trigger.getBoundingClientRect();
				var vh = window.innerHeight;
				var spaceBelow = vh - rect.bottom - edgeMargin;
				var spaceAbove = rect.top - edgeMargin;
				drp.drops = spaceBelow >= spaceAbove ? 'down' : 'up';
			}

			drp.move = function () {
				resolveViewportDrops();
				return origMove();
			};

			function onDrpScrollOrResize() {
				if (drp.isShowing) {
					drp.move();
				}
			}

			$btn.on('show.daterangepicker.becViewportDrops', function () {
				window.addEventListener('scroll', onDrpScrollOrResize, true);
			});
			$btn.on('hide.daterangepicker.becViewportDrops', function () {
				window.removeEventListener('scroll', onDrpScrollOrResize, true);
			});
		})();

		(function patchDrpMobileSheetHide() {
			if (!drp || typeof drp.hide !== 'function') {
				return;
			}
			var mqSheet = window.matchMedia('(max-width: 639px)');
			var reduceSheet = window.matchMedia('(prefers-reduced-motion: reduce)');
			var origHide = drp.hide.bind(drp);
			drp.hide = function () {
				var $c = drp.container;
				/* Second hide() during mobile slide-out (e.g. mousedown outside + backdrop click)
				 * must not strip bec-drp-is-closing and call origHide early — that cancels the animation. */
				if ($c && $c.length && $c.hasClass('bec-drp-is-closing')) {
					return;
				}
				var shouldAnimate =
					mqSheet.matches &&
					!reduceSheet.matches &&
					$c &&
					$c.length &&
					$c.hasClass('bec-drp-is-open') &&
					!$c.hasClass('bec-drp-is-closing');
				if (!shouldAnimate) {
					return origHide();
				}
				$c.addClass('bec-drp-is-closing');
				var el = $c[0];
				var finished = false;
				function done() {
					if (finished) {
						return;
					}
					finished = true;
					el.removeEventListener('animationend', onEnd);
					$c.removeClass('bec-drp-is-closing');
					origHide();
				}
				function onEnd(e) {
					if (e.target === el && e.animationName === 'bec-drp-sheet-exit') {
						done();
					}
				}
				el.addEventListener('animationend', onEnd);
				window.setTimeout(done, 400);
			};
		})();

		if (ci && co) {
			updateSplit($wrap, start, end);
		} else {
			updateSplit($wrap, null, null);
		}

		$btn.on('apply.daterangepicker', function (ev, picker) {
			$inCheckin.val(picker.startDate.format('YYYY-MM-DD'));
			$inCheckout.val(picker.endDate.format('YYYY-MM-DD'));
			dispatchBecNativeInputChange($inCheckin);
			dispatchBecNativeInputChange($inCheckout);
			$inCheckin.trigger('change');
			$inCheckout.trigger('change');
			updateSplit($wrap, picker.startDate, picker.endDate);
			$btn.attr('aria-expanded', 'false');
			try {
				form.dispatchEvent(new CustomEvent('bec:daterange-applied'));
			} catch (err) {}
		});

		$btn.on('hide.daterangepicker', function () {
			$btn.attr('aria-expanded', 'false');
			if (drp.container && drp.container.length) {
				drp.container.removeClass('bec-drp-is-open bec-drp-is-closing');
			}
			syncBackdropWithDaterange(false);
		});

		$btn.on('show.daterangepicker', function () {
			$btn.attr('aria-expanded', 'true');
			if (drp.container && drp.container.length) {
				drp.container.removeClass('bec-drp-is-closing').addClass('bec-drp-is-open');
			}
			ensureDrpScrollWrap();
			var s = $inCheckin.val() ? moment($inCheckin.val(), 'YYYY-MM-DD', true) : null;
			var e = $inCheckout.val() ? moment($inCheckout.val(), 'YYYY-MM-DD', true) : null;
			if (s && s.isValid() && e && e.isValid()) {
				drp.setStartDate(s);
				drp.setEndDate(e);
			}
			syncBackdropWithDaterange(true);
		});
	}

	$(function () {
		$('form.bec-search-form--enhanced').each(function () {
			initDaterange(this);
		});
	});
})(jQuery);
