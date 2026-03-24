/**
 * Date range picker (https://www.daterangepicker.com/) + Moment.js for BEC enhanced search.
 */
(function ($) {
	'use strict';

	function getCfg() {
		return typeof window.becSearchForm === 'object' && window.becSearchForm ? window.becSearchForm : {};
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

		$in.text(start.format('D'));
		$imy.text(start.format('MMMM YYYY'));
		$idow.text(start.format('dddd'));
		$out.text(end.format('D'));
		$omy.text(end.format('MMMM YYYY'));
		$odow.text(end.format('dddd'));
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
			showDropdowns: true,
			showCustomRangeLabel: false,
			opens: 'center',
			drops: 'down',
			parentEl: 'body',
			maxSpan: { days: maxNights },
			locale: {
				format: 'YYYY-MM-DD',
				separator: ' – ',
				applyLabel: cfg.applyLabel || 'Apply',
				cancelLabel: cfg.cancelLabel || 'Cancel',
				fromLabel: cfg.checkinLabel || 'Check-in',
				toLabel: cfg.checkoutLabel || 'Check-out',
				customRangeLabel: 'Custom',
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

		if (ci && co) {
			updateSplit($wrap, start, end);
		} else {
			updateSplit($wrap, null, null);
		}

		$btn.on('apply.daterangepicker', function (ev, picker) {
			$inCheckin.val(picker.startDate.format('YYYY-MM-DD'));
			$inCheckout.val(picker.endDate.format('YYYY-MM-DD'));
			updateSplit($wrap, picker.startDate, picker.endDate);
			$btn.attr('aria-expanded', 'false');
		});

		$btn.on('hide.daterangepicker', function () {
			$btn.attr('aria-expanded', 'false');
		});

		$btn.on('show.daterangepicker', function () {
			$btn.attr('aria-expanded', 'true');
			var s = $inCheckin.val() ? moment($inCheckin.val(), 'YYYY-MM-DD', true) : null;
			var e = $inCheckout.val() ? moment($inCheckout.val(), 'YYYY-MM-DD', true) : null;
			if (s && s.isValid() && e && e.isValid()) {
				drp.setStartDate(s);
				drp.setEndDate(e);
			}
		});
	}

	$(function () {
		$('form.bec-search-form--enhanced').each(function () {
			initDaterange(this);
		});
	});
})(jQuery);
