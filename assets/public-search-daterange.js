/**
 * Date range picker (https://www.daterangepicker.com/) + Moment.js for BEC enhanced search.
 */
(function ($) {
	'use strict';

	function getCfg() {
		return typeof window.becSearchForm === 'object' && window.becSearchForm ? window.becSearchForm : {};
	}

	/**
	 * Bubble to native listeners on ancestors (e.g. booking summary’s root). jQuery’s
	 * .trigger("change") does not always do that for handlers added with addEventListener.
	 */
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
			return !!(gt && gt.getAttribute('aria-expanded') === 'true');
		}

		function syncBackdropWithDaterange(showing) {
			if (!wrap || !backdrop || !mqDrawer.matches) {
				return;
			}
			if (showing) {
				backdrop.hidden = false;
				wrap.classList.add('bec-search-form-wrap--popover-open');
				document.body.style.overflow = 'hidden';
			} else if (!isGuestPanelOpen()) {
				backdrop.hidden = true;
				wrap.classList.remove('bec-search-form-wrap--popover-open');
				document.body.style.overflow = '';
			}
		}

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
			drops: 'down',
			parentEl: 'body',
			maxSpan: { days: maxNights },
			locale: {
				format: 'YYYY-MM-DD',
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

		if (typeof cfg.maxDateFromToday === 'number' && cfg.maxDateFromToday > 0) {
			drpOpts.maxDate = moment().add(cfg.maxDateFromToday, 'days');
		}

		$btn.daterangepicker(drpOpts);

		var drp = $btn.data('daterangepicker');

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
