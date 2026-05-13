/**
 * Mobile booking summary: bottom bar + slide-in panel + client-side rate switching.
 */
(function () {
	'use strict';

	function findPanel(root, openBtn) {
		var id = openBtn.getAttribute('aria-controls');
		if (!id) {
			return null;
		}
		return root.querySelector('#' + id) || document.getElementById(id);
	}

	function initDrawer(root) {
		if (!root) {
			return;
		}
		var openBtn = root.querySelector('.bec-booking-summary__open-panel');
		if (!openBtn) {
			return;
		}
		var drawer = findPanel(root, openBtn);
		if (!drawer) {
			return;
		}
		var backdrop = root.querySelector('.bec-booking-summary__backdrop');
		var backBtn = root.querySelector('.bec-booking-summary__back');

		function setInert(node, on) {
			if (node) {
				if (on) {
					node.setAttribute('inert', '');
					node.setAttribute('data-bec-bsummary-closed', '1');
				} else {
					node.removeAttribute('inert');
					node.removeAttribute('data-bec-bsummary-closed');
				}
			}
		}

		function open() {
			if (backdrop) {
				backdrop.hidden = false;
				backdrop.setAttribute('aria-hidden', 'false');
			}
			document.body.classList.add('bec-booking-summary-body-lock');
			drawer.classList.add('is-open');
			drawer.setAttribute('aria-hidden', 'false');
			setInert(drawer, false);
			openBtn.setAttribute('aria-expanded', 'true');
			try {
				drawer.focus();
			} catch (e) {}
		}

		function close() {
			if (backdrop) {
				backdrop.hidden = true;
				backdrop.setAttribute('aria-hidden', 'true');
			}
			document.body.classList.remove('bec-booking-summary-body-lock');
			drawer.classList.remove('is-open');
			drawer.setAttribute('aria-hidden', 'true');
			setInert(drawer, true);
			openBtn.setAttribute('aria-expanded', 'false');
		}

		openBtn.addEventListener('click', function (e) {
			e.preventDefault();
			open();
		});
		if (backBtn) {
			backBtn.addEventListener('click', function (e) {
				e.preventDefault();
				close();
			});
		}
		if (backdrop) {
			backdrop.addEventListener('click', close);
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
				close();
			}
		});
	}

	function findRateLinkFromEventTarget(root, target) {
		var el = target;
		while (el && el !== root) {
			if (el.nodeType === 1 && el.tagName === 'A' && el.className.indexOf('bec-booking-summary__rate-link') !== -1) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	function setAllHtml(root, selector, html) {
		var list = root.querySelectorAll(selector);
		var i;
		for (i = 0; i < list.length; i++) {
			list[i].innerHTML = html;
		}
	}

	function applyRateState(root, state, rateId, hrefForHistory) {
		var headHtml = state.head_html || '';
		var brHtml = state.breakdown_html || '';
		var accHtml = state.accordions_html || '';
		var barHtml = state.bar_html || '';
		var contHtml = state.continue_html || '';

		setAllHtml(root, '[data-bec-bsummary-head]', headHtml);
		setAllHtml(root, '[data-bec-bsummary-breakdown]', brHtml);

		var accWraps = root.querySelectorAll('[data-bec-bsummary-accordions]');
		var j;
		for (j = 0; j < accWraps.length; j++) {
			accWraps[j].innerHTML = accHtml;
			if (accHtml) {
				accWraps[j].removeAttribute('hidden');
			} else {
				accWraps[j].setAttribute('hidden', 'hidden');
			}
		}

		setAllHtml(root, '[data-bec-bsummary-bar-amount]', barHtml);
		setAllHtml(root, '[data-bec-bsummary-continue]', contHtml);

		var links = root.querySelectorAll('a.bec-booking-summary__rate-link');
		var k;
		for (k = 0; k < links.length; k++) {
			var link = links[k];
			var rid = link.getAttribute('data-bec-rate-id') || '';
			var li = link.closest ? link.closest('li') : null;
			if (!li) {
				var p = link.parentElement;
				if (p && p.tagName === 'LI') {
					li = p;
				}
			}
			if (rid === rateId) {
				link.setAttribute('aria-checked', 'true');
				if (li) {
					li.classList.add('is-selected');
				}
			} else {
				link.setAttribute('aria-checked', 'false');
				if (li) {
					li.classList.remove('is-selected');
				}
			}
		}

		if (hrefForHistory && window.history && window.history.replaceState) {
			try {
				window.history.replaceState(null, '', hrefForHistory);
			} catch (err) {}
		}
	}

	function initSearchFormSubmit(root) {
		if (!root) {
			return;
		}
		root.addEventListener('click', function (e) {
			var el = e.target;
			if (!el) {
				return;
			}
			if (el.nodeType !== 1) {
				el = el.parentElement;
			}
			if (!el || !el.closest) {
				return;
			}
			var btn = el.closest('[data-bec-submit-search-form]');
			if (!btn || !root.contains(btn)) {
				return;
			}
			if (btn.hasAttribute('disabled')) {
				return;
			}
			var fid = btn.getAttribute('data-bec-submit-search-form');
			if (!fid) {
				return;
			}
			// Prefer the form inside this summary: the root wrapper used to share the same id as
			// the form, so document.getElementById pointed at a div and submission was skipped.
			var form = root.querySelector('[data-bec-bsummary-search] form[id="' + fid.replace(/"/g, '') + '"]');
			if (!form || form.tagName !== 'FORM') {
				form = document.getElementById(fid);
			}
			if (!form || form.tagName !== 'FORM') {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			root.classList.add('is-loading');
			// Use submit(), not requestSubmit(): the footer button is outside the form and
			// requestSubmit() runs constraint validation (often failing on inputs in hidden
			// guest popovers), which aborts navigation with no feedback.
			form.submit();
		});
	}

	function bsummaryUiState(root) {
		return root.getAttribute('data-bec-bsummary-ui-state') || '';
	}

	function bsummaryFormSnapshot(form) {
		var parts = [];
		var ci = form.querySelector('input[name="bec_checkin"]');
		var co = form.querySelector('input[name="bec_checkout"]');
		parts.push('in=' + (ci && ci.value ? ci.value : ''));
		parts.push('out=' + (co && co.value ? co.value : ''));
		var tg = form.querySelector('input[name="bec_total_guests"]');
		if (tg) {
			parts.push('tg=' + (tg.value || ''));
		}
		var ad = form.querySelector('input[name="bec_adults"]');
		if (ad) {
			parts.push('ad=' + (ad.value || ''));
		}
		var ch = form.querySelector('input[name="bec_children"]');
		if (ch) {
			parts.push('ch=' + (ch.value || ''));
		}
		var ages = form.querySelectorAll('[name="bec_child_age[]"]');
		var i;
		for (i = 0; i < ages.length; i++) {
			parts.push('ca' + i + '=' + (ages[i].value || ''));
		}
		return parts.join('|');
	}

	function bsummaryFormSearchComplete(form) {
		var ci = form.querySelector('input[name="bec_checkin"]');
		var co = form.querySelector('input[name="bec_checkout"]');
		if (!ci || !co) {
			return false;
		}
		var v1 = (ci.value || '').trim();
		var v2 = (co.value || '').trim();
		if (!v1 || !v2) {
			return false;
		}
		if (!/^\d{4}-\d{2}-\d{2}$/.test(v1) || !/^\d{4}-\d{2}-\d{2}$/.test(v2)) {
			return false;
		}
		if (v2 <= v1) {
			return false;
		}
		// Occupancy defaults to at least 1 guest / adult when the field is blank (classic
		// markup, or before guest popover JS normalises). Match server + submit handler so
		// Check availability enables after valid dates without an extra guest interaction.
		var mode = form.getAttribute('data-bec-guest-mode') || '';
		if (mode === 'total') {
			var tg = form.querySelector('input[name="bec_total_guests"]');
			var n = tg ? parseInt(tg.value, 10) : NaN;
			if (isNaN(n) || n < 1) {
				n = 1;
			}
			return n >= 1;
		}
		var adu = form.querySelector('input[name="bec_adults"]');
		var a = adu ? parseInt(adu.value, 10) : NaN;
		if (isNaN(a) || a < 1) {
			a = 1;
		}
		return a >= 1;
	}

	function bsummaryUpdateCheckButtons(root, form, baselines) {
		var formId = form.id;
		if (!formId) {
			return;
		}
		var ui = bsummaryUiState(root);
		var complete = bsummaryFormSearchComplete(form);
		var snap = bsummaryFormSnapshot(form);
		var base = baselines[formId];
		if (base === undefined) {
			base = '';
		}
		var enable = false;
		if (!complete) {
			enable = false;
		} else if (ui === 'incomplete') {
			enable = true;
		} else {
			enable = snap !== base;
		}
		var buttons = root.querySelectorAll('[data-bec-submit-search-form="' + formId + '"]');
		var i;
		for (i = 0; i < buttons.length; i++) {
			var btn = buttons[i];
			if (enable) {
				btn.removeAttribute('disabled');
				btn.removeAttribute('aria-disabled');
			} else {
				btn.setAttribute('disabled', 'disabled');
				btn.setAttribute('aria-disabled', 'true');
			}
		}
	}

	function bsummaryFindForms(root) {
		var wraps = root.querySelectorAll('[data-bec-bsummary-search] form');
		var out = [];
		var seen = {};
		var i;
		for (i = 0; i < wraps.length; i++) {
			var f = wraps[i];
			if (f && f.id && !seen[f.id]) {
				seen[f.id] = true;
				out.push(f);
			}
		}
		return out;
	}

	function syncTwinSearchForms(root, sourceForm) {
		var forms = bsummaryFindForms(root);
		if (forms.length !== 2 || !sourceForm) {
			return;
		}
		var other = null;
		var i;
		for (i = 0; i < forms.length; i++) {
			if (forms[i] !== sourceForm) {
				other = forms[i];
				break;
			}
		}
		if (!other) {
			return;
		}
		var names = ['bec_checkin', 'bec_checkout', 'bec_adults', 'bec_children', 'bec_total_guests'];
		var changed = false;
		var j;
		for (j = 0; j < names.length; j++) {
			var nm = names[j];
			var sEl = sourceForm.querySelector('[name="' + nm + '"]');
			var oEl = other.querySelector('[name="' + nm + '"]');
			if (sEl && oEl && sEl.value !== oEl.value) {
				oEl.value = sEl.value;
				changed = true;
			}
		}
		if (changed) {
			try {
				other.dispatchEvent(new Event('change', { bubbles: true }));
			} catch (e1) {}
		}
	}

	function bsummaryApplyQuoteStaleUi(root, isStale) {
		root.classList.toggle('bec-booking-summary--quote-stale', isStale);
		var retries = root.querySelectorAll('[data-bec-bsummary-check-retry]');
		var r;
		for (r = 0; r < retries.length; r++) {
			if (isStale) {
				retries[r].removeAttribute('hidden');
			} else {
				retries[r].setAttribute('hidden', 'hidden');
			}
		}
	}

	function initBookingSummaryAvailabilityUi(root) {
		if (!root) {
			return;
		}
		var baselines = {};

		function captureBaselines() {
			var forms = bsummaryFindForms(root);
			var i;
			for (i = 0; i < forms.length; i++) {
				baselines[forms[i].id] = bsummaryFormSnapshot(forms[i]);
			}
		}

		function scheduleUpdate() {
			var forms = bsummaryFindForms(root);
			var i;
			var ui = bsummaryUiState(root);
			for (i = 0; i < forms.length; i++) {
				bsummaryUpdateCheckButtons(root, forms[i], baselines);
			}
			var isStale = false;
			if (ui === 'available') {
				for (i = 0; i < forms.length; i++) {
					var f = forms[i];
					var sid = f.id;
					var b = baselines[sid];
					if (b === undefined) {
						b = '';
					}
					if (bsummaryFormSnapshot(f) !== b) {
						isStale = true;
						break;
					}
				}
			}
			bsummaryApplyQuoteStaleUi(root, isStale);
		}

		captureBaselines();
		scheduleUpdate();
		window.setTimeout(scheduleUpdate, 0);
		window.setTimeout(scheduleUpdate, 150);

		function onFormFieldEvent(e) {
			var t = e.target;
			if (!t || !t.closest) {
				return;
			}
			var f = t.closest('form');
			if (!f || !root.contains(f) || !f.closest('[data-bec-bsummary-search]')) {
				return;
			}
			if (bsummaryUiState(root) === 'available') {
				syncTwinSearchForms(root, f);
			}
			scheduleUpdate();
		}

		root.addEventListener('input', onFormFieldEvent);
		root.addEventListener('change', onFormFieldEvent);

		root.addEventListener(
			'submit',
			function (e) {
				var form = e.target;
				if (!form || form.tagName !== 'FORM' || !root.contains(form)) {
					return;
				}
				if (!form.closest('[data-bec-bsummary-search]')) {
					return;
				}
				root.classList.add('is-loading');
			},
			true
		);
	}

	function initRateSwitch(root) {
		if (!root) {
			return;
		}
		var st = root.querySelector('script[data-bec-bsummary-state], script.bec-booking-summary__state');
		if (!st || !st.textContent) {
			return;
		}
		var data;
		try {
			data = JSON.parse(st.textContent);
		} catch (e) {
			return;
		}
		var states = data.states;
		if (!states || typeof states !== 'object') {
			return;
		}

		root.addEventListener('click', function (e) {
			var a = findRateLinkFromEventTarget(root, e.target);
			if (!a) {
				return;
			}
			var rid = a.getAttribute('data-bec-rate-id');
			if (!rid) {
				return;
			}
			var s = states[rid];
			if (!s) {
				return;
			}
			e.preventDefault();
			var href = a.getAttribute('href') || '';
			applyRateState(root, s, rid, href);
		});
	}

	var list = document.querySelectorAll('[data-bec-booking-summary]');
	var n;
	for (n = 0; n < list.length; n++) {
		initDrawer(list[n]);
		initBookingSummaryAvailabilityUi(list[n]);
		initSearchFormSubmit(list[n]);
		initRateSwitch(list[n]);
	}
})();
