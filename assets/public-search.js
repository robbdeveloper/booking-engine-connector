/**
 * Booking Engine Connector — enhanced search form (popovers, drawers, summaries).
 */
(function () {
	'use strict';

	/**
	 * @param {string} fmt
	 * @param {number} a1
	 * @param {number} [a2]
	 */
	function sprintfPhpStyle(fmt, a1, a2) {
		if (!fmt) {
			return '';
		}
		var nums = [a1, a2];
		var pos = 0;
		var s = fmt.replace(/%(\d+)\$d/g, function (_, n) {
			return String(nums[parseInt(n, 10) - 1] ?? '');
		});
		s = s.replace(/%d/g, function () {
			return String(nums[pos++] ?? '');
		});
		return s;
	}

	/**
	 * @param {HTMLInputElement} childrenInput
	 */
	function getChildCountFromInput(childrenInput) {
		if (!(childrenInput instanceof HTMLInputElement)) {
			return 0;
		}
		return Math.max(0, parseInt(childrenInput.value, 10) || 0);
	}

	/**
	 * @param {string} formId
	 * @param {number} index
	 * @param {string} selectedValue
	 */
	function createChildAgeRowSelect(formId, index, selectedValue) {
		var cfg = typeof becSearchForm === 'object' && becSearchForm ? becSearchForm : {};
		var labelFmt = cfg.strChildAgeLabel || 'Child %d age';
		var placeholder = cfg.strChildAgePlaceholder || 'Age';
		var div = document.createElement('div');
		div.className = 'bec-search-form__child-age';
		div.setAttribute('data-bec-child-age-index', String(index));
		var label = document.createElement('label');
		label.setAttribute('for', formId + '-child-age-' + index);
		label.textContent = sprintfPhpStyle(labelFmt, index + 1);
		var sel = document.createElement('select');
		sel.id = formId + '-child-age-' + index;
		sel.name = 'bec_child_age[]';
		var o0 = document.createElement('option');
		o0.value = '';
		o0.textContent = placeholder;
		sel.appendChild(o0);
		var a;
		for (a = 0; a <= 17; a++) {
			var o = document.createElement('option');
			o.value = String(a);
			o.textContent = String(a);
			if (selectedValue !== '' && String(selectedValue) === String(a)) {
				o.selected = true;
			}
			sel.appendChild(o);
		}
		if (selectedValue === '') {
			sel.selectedIndex = 0;
		}
		div.appendChild(label);
		div.appendChild(sel);
		return div;
	}

	/**
	 * @param {string} formId
	 * @param {number} index
	 * @param {string} value
	 */
	function createChildAgeRowInput(formId, index, value) {
		var cfg = typeof becSearchForm === 'object' && becSearchForm ? becSearchForm : {};
		var labelFmt = cfg.strChildAgeLabel || 'Child %d age';
		var p = document.createElement('p');
		p.className = 'bec-search-form__field bec-search-form__field--bec-child-age';
		p.setAttribute('data-bec-child-age-index', String(index));
		var label = document.createElement('label');
		label.setAttribute('for', formId + '-child-age-' + index);
		label.textContent = sprintfPhpStyle(labelFmt, index + 1);
		var inp = document.createElement('input');
		inp.id = formId + '-child-age-' + index;
		inp.name = 'bec_child_age[]';
		inp.type = 'number';
		inp.min = '0';
		inp.max = '17';
		if (value !== undefined && value !== null && value !== '') {
			inp.value = String(value);
		}
		p.appendChild(label);
		p.appendChild(document.createTextNode(' '));
		p.appendChild(inp);
		return p;
	}

	/**
	 * @param {HTMLElement} agesRoot
	 * @param {HTMLFormElement} form
	 * @param {{ isEnhanced?: boolean, onAfterSync?: () => void }} options
	 */
	function initDynamicChildAges(agesRoot, form, options) {
		if (!agesRoot || !form) {
			return;
		}
		if (agesRoot.getAttribute('data-bec-child-ages-init') === '1') {
			return;
		}
		agesRoot.setAttribute('data-bec-child-ages-init', '1');
		var isEnhanced = options && options.isEnhanced === true;
		var onAfterSync = options && typeof options.onAfterSync === 'function' ? options.onAfterSync : null;
		var maxSlots = parseInt(agesRoot.getAttribute('data-bec-max-child-age-slots') || '8', 10);
		if (isNaN(maxSlots) || maxSlots < 1) {
			maxSlots = 8;
		}
		var formId = agesRoot.getAttribute('data-bec-form-id') || '';
		var childrenInput = form.querySelector('[name="bec_children"]');
		if (!childrenInput) {
			return;
		}
		function sync() {
			var n = getChildCountFromInput(childrenInput);
			n = Math.min(n, maxSlots);
			var rows;
			while (agesRoot.querySelectorAll('[data-bec-child-age-index]').length > n) {
				rows = agesRoot.querySelectorAll('[data-bec-child-age-index]');
				rows[rows.length - 1].remove();
			}
			for (rows = agesRoot.querySelectorAll('[data-bec-child-age-index]'); rows.length < n; ) {
				var idx = rows.length;
				if (isEnhanced) {
					agesRoot.appendChild(createChildAgeRowSelect(formId, idx, ''));
				} else {
					agesRoot.appendChild(createChildAgeRowInput(formId, idx, ''));
				}
				rows = agesRoot.querySelectorAll('[data-bec-child-age-index]');
			}
			if (onAfterSync) {
				onAfterSync();
			}
		}
		childrenInput.addEventListener('input', sync);
		childrenInput.addEventListener('change', sync);
		form.addEventListener('submit', sync);
		sync();
	}

	function initClassicChildAgesInDocument() {
		document.querySelectorAll('.bec-search-form__child-ages[data-bec-child-ages-root]').forEach(function (agesRoot) {
			var form = agesRoot.closest('form');
			if (!form || form.classList.contains('bec-search-form--enhanced')) {
				return;
			}
			initDynamicChildAges(agesRoot, form, { isEnhanced: false });
		});
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function initEnhancedForm(form) {
		if (!form || form.dataset.becSearchEnhanced === '1') {
			return;
		}
		form.dataset.becSearchEnhanced = '1';

		var wrap = form.closest('.bec-search-form-wrap--enhanced');
		var backdrop = wrap ? wrap.querySelector('.bec-search-form__backdrop') : null;
		var mqDrawer = window.matchMedia('(max-width: 639px)');

		var openTrigger = null;
		var openPanel = null;

		function setBodyScrollLock(lock) {
			if (lock) {
				document.body.style.overflow = 'hidden';
			} else {
				document.body.style.overflow = '';
			}
		}

		function closeAll() {
			form.querySelectorAll('.bec-search-form__trigger').forEach(function (btn) {
				btn.setAttribute('aria-expanded', 'false');
			});
			form.querySelectorAll('.bec-search-form__panel').forEach(function (panel) {
				panel.hidden = true;
				panel.classList.remove('bec-search-form__panel--open');
			});
			if (backdrop) {
				backdrop.hidden = true;
			}
			if (wrap) {
				wrap.classList.remove('bec-search-form-wrap--popover-open');
			}
			setBodyScrollLock(false);
			openTrigger = null;
			openPanel = null;
		}

		function openPanelFor(trigger) {
			var panelId = trigger.getAttribute('aria-controls');
			var panel = panelId ? document.getElementById(panelId) : null;
			if (!panel) {
				return;
			}
			closeAll();
			trigger.setAttribute('aria-expanded', 'true');
			panel.hidden = false;
			if (mqDrawer.matches) {
				panel.classList.add('bec-search-form__panel--open');
				if (backdrop) {
					backdrop.hidden = false;
				}
				if (wrap) {
					wrap.classList.add('bec-search-form-wrap--popover-open');
				}
				setBodyScrollLock(true);
			}
			openTrigger = trigger;
			openPanel = panel;
		}

		function togglePanel(trigger) {
			var panelId = trigger.getAttribute('aria-controls');
			var panel = panelId ? document.getElementById(panelId) : null;
			if (!panel) {
				return;
			}
			var expanded = trigger.getAttribute('aria-expanded') === 'true';
			if (expanded) {
				closeAll();
			} else {
				openPanelFor(trigger);
			}
		}

		form.querySelectorAll('.bec-search-form__trigger').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				togglePanel(btn);
			});
		});

		if (backdrop) {
			backdrop.addEventListener('click', function () {
				closeAll();
			});
		}

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closeAll();
			}
		});

		document.addEventListener('click', function (e) {
			if (!wrap || !openPanel || !openTrigger) {
				return;
			}
			var t = e.target;
			if (!(t instanceof Node)) {
				return;
			}
			if (mqDrawer.matches) {
				return;
			}
			if (openPanel.contains(t) || openTrigger.contains(t)) {
				return;
			}
			closeAll();
		});

		mqDrawer.addEventListener('change', function () {
			if (!openPanel) {
				return;
			}
			if (mqDrawer.matches) {
				openPanel.classList.add('bec-search-form__panel--open');
				if (backdrop) {
					backdrop.hidden = false;
				}
				if (wrap) {
					wrap.classList.add('bec-search-form-wrap--popover-open');
				}
				setBodyScrollLock(true);
			} else {
				openPanel.classList.remove('bec-search-form__panel--open');
				if (backdrop) {
					backdrop.hidden = true;
				}
				if (wrap) {
					wrap.classList.remove('bec-search-form-wrap--popover-open');
				}
				setBodyScrollLock(false);
			}
		});

		var guestMode = form.getAttribute('data-bec-guest-mode') || 'breakdown';
		var adultsInput = form.querySelector('[name="bec_adults"]');
		var childrenInput = form.querySelector('[name="bec_children"]');
		var totalGuestsInput = form.querySelector('[name="bec_total_guests"]');
		var guestSummary = form.querySelector('[data-bec-guest-summary]');
		var childAgesRoot = form.querySelector('.bec-search-form__child-ages[data-bec-child-ages-root]');

		function formatGuests() {
			if (!guestSummary) {
				return;
			}
			var cfg = typeof window.becSearchForm === 'object' && window.becSearchForm ? window.becSearchForm : {};
			if (guestMode === 'total') {
				if (!(totalGuestsInput instanceof HTMLInputElement)) {
					return;
				}
				var t = Math.max(1, parseInt(totalGuestsInput.value, 10) || 1);
				if (!totalGuestsInput.value || parseInt(totalGuestsInput.value, 10) < 1) {
					totalGuestsInput.value = '1';
				}
				var tplGOne = cfg.strGuestsOne || '%d guest';
				var tplGMany = cfg.strGuestsMany || '%d guests';
				guestSummary.textContent = sprintfPhpStyle(t === 1 ? tplGOne : tplGMany, t);
				return;
			}
			var a = adultsInput instanceof HTMLInputElement ? Math.max(1, parseInt(adultsInput.value, 10) || 1) : 1;
			var c = childrenInput instanceof HTMLInputElement ? Math.max(0, parseInt(childrenInput.value, 10) || 0) : 0;
			if (adultsInput instanceof HTMLInputElement && (!adultsInput.value || parseInt(adultsInput.value, 10) < 1)) {
				adultsInput.value = '1';
			}
			var tplOne = cfg.strAdultsOne || '%d adult';
			var tplMany = cfg.strAdultsMany || '%d adults';
			var tplKids = cfg.strWithChildren || '%1$d adults · %2$d children';
			if (c === 0) {
				guestSummary.textContent = sprintfPhpStyle(a === 1 ? tplOne : tplMany, a);
			} else {
				guestSummary.textContent = sprintfPhpStyle(tplKids, a, c);
			}
		}

		if (guestMode === 'total') {
			if (totalGuestsInput instanceof HTMLInputElement) {
				totalGuestsInput.addEventListener('input', formatGuests);
				totalGuestsInput.addEventListener('change', formatGuests);
			}
		} else {
			if (childAgesRoot) {
				initDynamicChildAges(childAgesRoot, form, { isEnhanced: true, onAfterSync: formatGuests });
			} else if (childrenInput instanceof HTMLInputElement) {
				childrenInput.addEventListener('input', formatGuests);
				childrenInput.addEventListener('change', formatGuests);
			}
			if (adultsInput instanceof HTMLInputElement) {
				adultsInput.addEventListener('input', formatGuests);
				adultsInput.addEventListener('change', formatGuests);
			}
		}

		form.querySelectorAll('.bec-search-form__stepper').forEach(function (stepper) {
			var targetName = stepper.getAttribute('data-bec-stepper-for');
			var input = targetName ? form.querySelector('[name="' + targetName.replace(/"/g, '\\"') + '"]') : null;
			if (!(input instanceof HTMLInputElement)) {
				return;
			}
			var min = input.min !== '' ? parseInt(input.min, 10) : NaN;
			var max = input.max !== '' ? parseInt(input.max, 10) : NaN;
			stepper.querySelectorAll('[data-bec-step]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var delta = parseInt(btn.getAttribute('data-bec-step') || '0', 10);
					var v = parseInt(input.value, 10) || 0;
					v += delta;
					if (!isNaN(min)) {
						v = Math.max(min, v);
					}
					if (!isNaN(max)) {
						v = Math.min(max, v);
					}
					input.value = String(v);
					input.dispatchEvent(new Event('input', { bubbles: true }));
					input.dispatchEvent(new Event('change', { bubbles: true }));
				});
			});
		});

		formatGuests();

		form.addEventListener('submit', function () {
			if (guestMode === 'total') {
				if (totalGuestsInput instanceof HTMLInputElement) {
					var tv = parseInt(totalGuestsInput.value, 10);
					if (!tv || tv < 1) {
						totalGuestsInput.value = '1';
					}
				}
				return;
			}
			if (adultsInput instanceof HTMLInputElement) {
				var av = parseInt(adultsInput.value, 10);
				if (!av || av < 1) {
					adultsInput.value = '1';
				}
			}
		});
	}

	document.querySelectorAll('form.bec-search-form--enhanced').forEach(initEnhancedForm);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initClassicChildAgesInDocument);
	} else {
		initClassicChildAgesInDocument();
	}
})();
