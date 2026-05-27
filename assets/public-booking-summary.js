/**
 * Mobile booking summary: bottom bar + slide-in panel + client-side rate switching.
 */
(function () {
	'use strict';

	/**
	 * First control associated with the form (includes fields with form="…" outside the form node).
	 * @param {HTMLFormElement} form
	 * @param {string} name
	 * @returns {Element | null}
	 */
	function becFormNamedControl(form, name) {
		if (!form || !form.elements) {
			return null;
		}
		var el = form.elements.namedItem(name);
		if (!el) {
			return null;
		}
		if (typeof RadioNodeList !== 'undefined' && el instanceof RadioNodeList) {
			return el.length ? el[0] : null;
		}
		if (el && typeof el.length === 'number' && !el.tagName && typeof el.item === 'function') {
			return el.length ? el.item(0) : null;
		}
		return el;
	}

	/**
	 * @param {HTMLFormElement} form
	 * @param {string} name
	 * @returns {Element[]}
	 */
	function becFormNamedControls(form, name) {
		var out = [];
		if (!form || !form.elements) {
			return out;
		}
		var i;
		for (i = 0; i < form.elements.length; i++) {
			var c = form.elements[i];
			if (c && c.getAttribute && c.getAttribute('name') === name) {
				out.push(c);
			}
		}
		return out;
	}

	/**
	 * Body-level mount for the portaled mobile drawer (after portalMobileDrawer).
	 * @param {Element} root
	 * @returns {Element | null}
	 */
	function getDrawerMount(root) {
		if (!root || !root.id) {
			return null;
		}
		var safeId =
			typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
				? CSS.escape(root.id)
				: root.id.replace(/"/g, '\\"');
		return document.querySelector(
			'.bec-booking-summary__drawer-mount[data-bec-bsummary-root-id="' + safeId + '"]'
		);
	}

	/**
	 * Move backdrop + drawer to document.body (mirrors guest popover mount pattern).
	 * @param {Element} root
	 * @returns {Element | null}
	 */
	function portalMobileDrawer(root) {
		if (!root) {
			return null;
		}
		var existing = getDrawerMount(root);
		if (existing) {
			return existing;
		}
		if (root.dataset.becBsummaryDrawerPortaled === '1') {
			return getDrawerMount(root);
		}

		var mobile = root.querySelector('.bec-booking-summary__mobile');
		if (!mobile) {
			return null;
		}
		var backdrop = mobile.querySelector('.bec-booking-summary__backdrop');
		var drawer = mobile.querySelector('.bec-booking-summary__drawer');
		if (!drawer) {
			return null;
		}

		var mount = document.createElement('div');
		mount.className = 'bec-booking-summary bec-booking-summary__drawer-mount';
		mount.classList.add('bec-booking-summary__mobile');
		if (root.classList.contains('bec-booking-summary--preset-compact')) {
			mount.classList.add('bec-booking-summary--preset-compact');
		}
		mount.setAttribute('data-bec-bsummary-drawer-mount', '');
		mount.setAttribute('data-bec-bsummary-root-id', root.id);
		mount.setAttribute('aria-hidden', 'true');

		if (backdrop) {
			mount.appendChild(backdrop);
		}
		mount.appendChild(drawer);
		document.body.appendChild(mount);

		root.dataset.becBsummaryDrawerPortaled = '1';
		return mount;
	}

	/**
	 * @param {Element} root
	 * @param {string} selector
	 * @returns {Element[]}
	 */
	function querySummaryAll(root, selector) {
		var out = [];
		var mount = getDrawerMount(root);
		var nodes = root.querySelectorAll(selector);
		var i;
		for (i = 0; i < nodes.length; i++) {
			out.push(nodes[i]);
		}
		if (mount) {
			nodes = mount.querySelectorAll(selector);
			for (i = 0; i < nodes.length; i++) {
				out.push(nodes[i]);
			}
		}
		return out;
	}

	/**
	 * @param {Element} root
	 * @param {Element | null} el
	 * @returns {boolean}
	 */
	function summaryContains(root, el) {
		if (!el) {
			return false;
		}
		if (root.contains(el)) {
			return true;
		}
		var mount = getDrawerMount(root);
		return mount ? mount.contains(el) : false;
	}

	/**
	 * @param {Element} root
	 * @param {string} type
	 * @param {EventListener} handler
	 * @param {boolean | AddEventListenerOptions} [options]
	 */
	function bindSummaryDelegated(root, type, handler, options) {
		root.addEventListener(type, handler, options);
		var mount = getDrawerMount(root);
		if (mount) {
			mount.addEventListener(type, handler, options);
		}
	}

	/**
	 * @param {Element} root
	 * @param {boolean} loading
	 */
	function setSummaryLoading(root, loading) {
		root.classList.toggle('is-loading', loading);
		var mount = getDrawerMount(root);
		if (mount) {
			mount.classList.toggle('is-loading', loading);
		}
	}

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

		portalMobileDrawer(root);

		var drawer = findPanel(root, openBtn);
		if (!drawer) {
			return;
		}
		var mount = getDrawerMount(root);
		var backdrop = mount
			? mount.querySelector('.bec-booking-summary__backdrop')
			: root.querySelector('.bec-booking-summary__backdrop');
		var backBtn = drawer.querySelector('.bec-booking-summary__back');

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
			if (mount) {
				mount.setAttribute('aria-hidden', 'false');
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
			if (mount) {
				mount.setAttribute('aria-hidden', 'true');
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
		while (el) {
			if (el.nodeType === 1 && el.tagName === 'A' && el.className.indexOf('bec-booking-summary__rate-link') !== -1) {
				if (summaryContains(root, el)) {
					return el;
				}
			}
			if (el === root) {
				break;
			}
			var mount = getDrawerMount(root);
			if (mount && el === mount) {
				break;
			}
			el = el.parentElement;
		}
		return null;
	}

	function setAllHtml(root, selector, html) {
		var list = querySummaryAll(root, selector);
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

		var accWraps = querySummaryAll(root, '[data-bec-bsummary-accordions]');
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

		var links = querySummaryAll(root, 'a.bec-booking-summary__rate-link');
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
		bindSummaryDelegated(root, 'click', function (e) {
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
			if (!btn || !summaryContains(root, btn)) {
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
			var safeId = fid.replace(/"/g, '');
			var forms = querySummaryAll(root, '[data-bec-bsummary-search] form[id="' + safeId + '"]');
			var form = forms.length ? forms[0] : null;
			if (!form || form.tagName !== 'FORM') {
				form = document.getElementById(fid);
			}
			if (!form || form.tagName !== 'FORM') {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			setSummaryLoading(root, true);
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
		var tg = becFormNamedControl(form, 'bec_total_guests');
		if (tg) {
			parts.push('tg=' + (tg.value || ''));
		}
		var ad = becFormNamedControl(form, 'bec_adults');
		if (ad) {
			parts.push('ad=' + (ad.value || ''));
		}
		var ch = becFormNamedControl(form, 'bec_children');
		if (ch) {
			parts.push('ch=' + (ch.value || ''));
		}
		var ages = becFormNamedControls(form, 'bec_child_age[]');
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
			var tg = becFormNamedControl(form, 'bec_total_guests');
			var n = tg ? parseInt(tg.value, 10) : NaN;
			if (isNaN(n) || n < 1) {
				n = 1;
			}
			return n >= 1;
		}
		var adu = becFormNamedControl(form, 'bec_adults');
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
		var buttons = querySummaryAll(root, '[data-bec-submit-search-form="' + formId + '"]');
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
		var wraps = querySummaryAll(root, '[data-bec-bsummary-search] form');
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
			var sEl = becFormNamedControl(sourceForm, nm);
			var oEl = becFormNamedControl(other, nm);
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
		var mount = getDrawerMount(root);
		if (mount) {
			mount.classList.toggle('bec-booking-summary--quote-stale', isStale);
		}
		var retries = querySummaryAll(root, '[data-bec-bsummary-check-retry]');
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
			if (!f || !summaryContains(root, f) || !f.closest('[data-bec-bsummary-search]')) {
				return;
			}
			if (bsummaryUiState(root) === 'available') {
				syncTwinSearchForms(root, f);
			}
			scheduleUpdate();
		}

		bindSummaryDelegated(root, 'input', onFormFieldEvent);
		bindSummaryDelegated(root, 'change', onFormFieldEvent);

		bindSummaryDelegated(
			root,
			'submit',
			function (e) {
				var form = e.target;
				if (!form || form.tagName !== 'FORM' || !summaryContains(root, form)) {
					return;
				}
				if (!form.closest('[data-bec-bsummary-search]')) {
					return;
				}
				setSummaryLoading(root, true);
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

		bindSummaryDelegated(root, 'click', function (e) {
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

	function initBookingSummary(root) {
		if (!root || root.dataset.becBookingSummaryInit === '1') {
			return;
		}
		root.dataset.becBookingSummaryInit = '1';
		initDrawer(root);
		initBookingSummaryAvailabilityUi(root);
		initSearchFormSubmit(root);
		initRateSwitch(root);
	}

	var list = document.querySelectorAll('[data-bec-booking-summary]');
	var n;
	for (n = 0; n < list.length; n++) {
		initBookingSummary(list[n]);
	}
})();
