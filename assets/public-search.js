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
		if (formId) {
			sel.setAttribute('form', formId);
		}
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
	 * @param {{ isEnhanced?: boolean, onAfterSync?: () => void, fieldScope?: HTMLElement }} options
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
		var fieldScope =
			options && options.fieldScope instanceof HTMLElement ? options.fieldScope : form;
		var childrenInput = fieldScope.querySelector('[name="bec_children"]');
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
	 * @returns {'auto'|'top'|'bottom'}
	 */
	function getPopoverPlacement(form) {
		var raw = form.getAttribute('data-bec-popover-placement') || 'auto';
		raw = String(raw).toLowerCase().trim();
		if (raw === 'top' || raw === 'bottom') {
			return raw;
		}
		return 'auto';
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function initEnhancedForm(form) {
		if (!form || form.dataset.becSearchEnhanced === '1') {
			return;
		}
		form.dataset.becSearchEnhanced = '1';

		var popoverPlacement = getPopoverPlacement(form);

		var guestControl = form.querySelector('.bec-search-form__control--guests');
		var guestPanel = guestControl ? guestControl.querySelector('.bec-search-form__panel') : null;
		var guestTrigger = guestControl ? guestControl.querySelector('.bec-search-form__trigger') : null;

		if (guestPanel && guestTrigger) {
			var mount = document.createElement('div');
			mount.className = 'bec-search-form-wrap--enhanced bec-search-form__guest-panel-mount';
			mount.setAttribute('aria-hidden', 'true');
			document.body.appendChild(mount);
			mount.appendChild(guestPanel);
		}

		var fieldRoot = guestPanel || form;

		var wrap = form.closest('.bec-search-form-wrap--enhanced');
		var backdrop = wrap ? wrap.querySelector('.bec-search-form__backdrop') : null;
		var mqDrawer = window.matchMedia('(max-width: 639px)');

		var openTrigger = null;
		var openPanel = null;
		var repositionScheduled = false;
		var guestPanelCloseGen = 0;

		function setBodyScrollLock(lock) {
			if (lock) {
				document.body.style.overflow = 'hidden';
			} else {
				document.body.style.overflow = '';
			}
		}

		function clearGuestPanelDesktopPosition() {
			if (!guestPanel) {
				return;
			}
			guestPanel.classList.remove('bec-search-form__panel--portal-desktop');
			guestPanel.classList.remove(
				'bec-search-form__panel--placement-top',
				'bec-search-form__panel--placement-bottom'
			);
			guestPanel.style.top = '';
			guestPanel.style.left = '';
			guestPanel.style.width = '';
			guestPanel.style.bottom = '';
			guestPanel.style.right = '';
		}

		function positionGuestPanelDesktop() {
			if (!guestPanel || !guestTrigger || mqDrawer.matches) {
				return;
			}
			var rect = guestTrigger.getBoundingClientRect();
			var pxRem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
			var gap = 0.35 * pxRem;
			var vw = window.innerWidth;
			var vh = window.innerHeight;
			var minW = Math.min(vw - 16, 18 * pxRem);
			var maxW = 22 * pxRem;
			var width = Math.min(maxW, Math.max(minW, rect.width));
			var left = rect.left;
			if (left + width > vw - 8) {
				left = Math.max(8, vw - width - 8);
			}
			if (left < 8) {
				left = 8;
			}
			guestPanel.classList.add('bec-search-form__panel--portal-desktop');
			guestPanel.classList.remove(
				'bec-search-form__panel--placement-top',
				'bec-search-form__panel--placement-bottom'
			);
			guestPanel.style.left = left + 'px';
			guestPanel.style.width = width + 'px';
			var ph = guestPanel.offsetHeight;
			var topBelow = rect.bottom + gap;
			var topAbove = rect.top - ph - gap;
			var spaceBelow = vh - topBelow - 8;
			var spaceAbove = rect.top - gap - 8;
			var top;
			var placement = popoverPlacement;

			if (placement === 'bottom') {
				top = topBelow;
				guestPanel.classList.add('bec-search-form__panel--placement-bottom');
			} else if (placement === 'top') {
				top = topAbove;
				if (ph > 0 && top < 8) {
					top = 8;
				}
				guestPanel.classList.add('bec-search-form__panel--placement-top');
			} else if (spaceBelow >= spaceAbove) {
				top = topBelow;
				guestPanel.classList.add('bec-search-form__panel--placement-bottom');
			} else {
				top = topAbove;
				if (ph > 0 && top < 8) {
					top = 8;
				}
				guestPanel.classList.add('bec-search-form__panel--placement-top');
			}
			guestPanel.style.top = top + 'px';
		}

		function schedulePositionGuestPanelDesktop() {
			if (!guestPanel || openPanel !== guestPanel || mqDrawer.matches) {
				return;
			}
			if (repositionScheduled) {
				return;
			}
			repositionScheduled = true;
			window.requestAnimationFrame(function () {
				repositionScheduled = false;
				positionGuestPanelDesktop();
			});
		}

		function onGuestPanelScrollOrResize() {
			schedulePositionGuestPanelDesktop();
		}

		function bindGuestPanelReposition() {
			window.addEventListener('scroll', onGuestPanelScrollOrResize, true);
			window.addEventListener('resize', onGuestPanelScrollOrResize);
		}

		function unbindGuestPanelReposition() {
			window.removeEventListener('scroll', onGuestPanelScrollOrResize, true);
			window.removeEventListener('resize', onGuestPanelScrollOrResize);
			repositionScheduled = false;
		}

		/** @type {Record<string, unknown> | null} */
		var guestPanelCommitSnapshot = null;

		/**
		 * @param {{ keepDaterange?: boolean }} [options]
		 * @param {() => void} [onClosed]
		 */
		function closeAll(options, onClosed) {
			if (typeof options === 'function') {
				onClosed = options;
				options = undefined;
			}
			onClosed = onClosed || function () {};
			var keepDaterange = options && options.keepDaterange === true;
			guestPanelCloseGen++;
			var myGen = guestPanelCloseGen;
			guestPanelCommitSnapshot = null;
			if (!keepDaterange) {
				var dateTrigger = form.querySelector('.bec-search-form__date-split');
				if (dateTrigger && typeof window.jQuery !== 'undefined') {
					var drp = window.jQuery(dateTrigger).data('daterangepicker');
					if (drp && typeof drp.hide === 'function') {
						drp.hide();
					}
				}
			}
			unbindGuestPanelReposition();
			clearGuestPanelDesktopPosition();
			form.querySelectorAll('.bec-search-form__trigger').forEach(function (btn) {
				btn.setAttribute('aria-expanded', 'false');
			});

			var reduceSheetMotion =
				typeof window.matchMedia === 'function' &&
				window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			var animateGuest =
				!reduceSheetMotion &&
				guestPanel &&
				mqDrawer.matches &&
				guestPanel.classList.contains('bec-search-form__panel--open');

			function finalizeCloseUi() {
				if (myGen !== guestPanelCloseGen) {
					return;
				}
				if (guestPanel) {
					guestPanel.hidden = true;
					guestPanel.classList.remove('bec-search-form__panel--open');
				}
				if (backdrop) {
					backdrop.hidden = true;
				}
				if (wrap) {
					wrap.classList.remove('bec-search-form-wrap--popover-open');
				}
				setBodyScrollLock(false);
				openTrigger = null;
				openPanel = null;
				onClosed();
			}

			if (animateGuest && guestPanel) {
				var gp = guestPanel;
				gp.classList.remove('bec-search-form__panel--open');
				var settled = false;
				function settle() {
					if (settled || myGen !== guestPanelCloseGen) {
						return;
					}
					settled = true;
					gp.removeEventListener('transitionend', onTe);
					finalizeCloseUi();
				}
				function onTe(ev) {
					if (ev.target !== gp || ev.propertyName !== 'transform') {
						return;
					}
					settle();
				}
				gp.addEventListener('transitionend', onTe);
				window.setTimeout(settle, 400);
			} else {
				finalizeCloseUi();
			}
		}

		function openPanelFor(trigger) {
			var panelId = trigger.getAttribute('aria-controls');
			var panel = panelId ? document.getElementById(panelId) : null;
			if (!panel) {
				return;
			}
			closeAll(undefined, function () {
				trigger.setAttribute('aria-expanded', 'true');
				panel.hidden = false;
				if (mqDrawer.matches) {
					panel.classList.remove('bec-search-form__panel--open');
					if (backdrop) {
						backdrop.hidden = false;
					}
					if (wrap) {
						wrap.classList.add('bec-search-form-wrap--popover-open');
					}
					setBodyScrollLock(true);
					var reduceSheetMotion =
						typeof window.matchMedia === 'function' &&
						window.matchMedia('(prefers-reduced-motion: reduce)').matches;
					if (reduceSheetMotion) {
						panel.classList.add('bec-search-form__panel--open');
					} else {
						window.requestAnimationFrame(function () {
							window.requestAnimationFrame(function () {
								panel.classList.add('bec-search-form__panel--open');
							});
						});
					}
				} else if (panel === guestPanel && guestTrigger) {
					bindGuestPanelReposition();
					window.requestAnimationFrame(function () {
						window.requestAnimationFrame(positionGuestPanelDesktop);
					});
				}
				openTrigger = trigger;
				openPanel = panel;
				if (panel === guestPanel) {
					captureGuestPanelCommitSnapshot();
				}
			});
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

		if (guestTrigger && guestPanel) {
			form.addEventListener('bec:daterange-applied', function () {
				window.requestAnimationFrame(function () {
					openPanelFor(guestTrigger);
					if (typeof guestPanel.focus === 'function') {
						guestPanel.focus();
					}
				});
			});
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

		var dateRangeWrap = form.querySelector('[data-bec-daterange]');

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
			/* Clicking the date control closes the guest popover but must not hide the
			 * daterangepicker: the same click opens it on the trigger first (bubble order),
			 * and closeAll() would immediately call drp.hide(). */
			if (dateRangeWrap instanceof HTMLElement && dateRangeWrap.contains(t)) {
				closeAll({ keepDaterange: true });
				return;
			}
			closeAll();
		});

		mqDrawer.addEventListener('change', function () {
			if (!openPanel) {
				return;
			}
			if (mqDrawer.matches) {
				unbindGuestPanelReposition();
				clearGuestPanelDesktopPosition();
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
				if (openPanel === guestPanel) {
					bindGuestPanelReposition();
					window.requestAnimationFrame(function () {
						window.requestAnimationFrame(positionGuestPanelDesktop);
					});
				}
			}
		});

		var guestMode = form.getAttribute('data-bec-guest-mode') || 'breakdown';
		var adultsInput = fieldRoot.querySelector('[name="bec_adults"]');
		var childrenInput = fieldRoot.querySelector('[name="bec_children"]');
		var totalGuestsInput = fieldRoot.querySelector('[name="bec_total_guests"]');
		var guestSummary = form.querySelector('[data-bec-guest-summary]');
		var childAgesRoot = fieldRoot.querySelector('.bec-search-form__child-ages[data-bec-child-ages-root]');

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

		function captureGuestPanelCommitSnapshot() {
			if (!guestPanel || openPanel !== guestPanel) {
				return;
			}
			if (guestMode === 'total') {
				if (totalGuestsInput instanceof HTMLInputElement) {
					guestPanelCommitSnapshot = { mode: 'total', total: totalGuestsInput.value };
				}
				return;
			}
			var ages = [];
			if (childAgesRoot) {
				childAgesRoot.querySelectorAll('select[name="bec_child_age[]"]').forEach(function (sel) {
					if (sel instanceof HTMLSelectElement) {
						ages.push(sel.value);
					}
				});
			}
			guestPanelCommitSnapshot = {
				mode: 'breakdown',
				adults: adultsInput instanceof HTMLInputElement ? adultsInput.value : '1',
				children: childrenInput instanceof HTMLInputElement ? childrenInput.value : '0',
				childAges: ages,
			};
		}

		function restoreGuestPanelCommitSnapshot() {
			var snap = guestPanelCommitSnapshot;
			if (!snap || typeof snap !== 'object') {
				return;
			}
			if (snap.mode === 'total' && totalGuestsInput instanceof HTMLInputElement && snap.total !== undefined) {
				totalGuestsInput.value = String(snap.total);
				return;
			}
			if (snap.mode !== 'breakdown') {
				return;
			}
			if (adultsInput instanceof HTMLInputElement && snap.adults !== undefined) {
				adultsInput.value = String(snap.adults);
			}
			if (childrenInput instanceof HTMLInputElement && snap.children !== undefined) {
				childrenInput.value = String(snap.children);
				childrenInput.dispatchEvent(new Event('input', { bubbles: true }));
				childrenInput.dispatchEvent(new Event('change', { bubbles: true }));
			}
			var aged = snap.childAges;
			if (childAgesRoot && Array.isArray(aged) && aged.length > 0) {
				childAgesRoot.querySelectorAll('select[name="bec_child_age[]"]').forEach(function (sel, idx) {
					var v = aged[idx];
					if (v !== undefined && sel instanceof HTMLSelectElement) {
						sel.value = String(v);
					}
				});
			}
		}

		if (guestPanel) {
			var guestActionsEl = guestPanel.querySelector('[data-bec-guest-actions="1"]');
			if (guestActionsEl) {
				guestActionsEl.addEventListener('click', function (e) {
					var t = e.target;
					var btn =
						t && typeof t.closest === 'function'
							? t.closest('[data-bec-guest-dismiss]')
							: null;
					if (!(btn instanceof HTMLButtonElement) || !(guestActionsEl instanceof HTMLElement) || !guestActionsEl.contains(btn)) {
						return;
					}
					var action = btn.getAttribute('data-bec-guest-dismiss');
					e.preventDefault();
					if (action === 'cancel') {
						restoreGuestPanelCommitSnapshot();
						formatGuests();
					}
					closeAll(undefined, function () {
						if (guestTrigger && typeof guestTrigger.focus === 'function') {
							window.requestAnimationFrame(function () {
								guestTrigger.focus();
							});
						}
					});
				});
			}
		}

		if (guestMode === 'total') {
			if (totalGuestsInput instanceof HTMLInputElement) {
				totalGuestsInput.addEventListener('input', formatGuests);
				totalGuestsInput.addEventListener('change', formatGuests);
			}
		} else {
			if (childAgesRoot) {
				initDynamicChildAges(childAgesRoot, form, {
					isEnhanced: true,
					onAfterSync: formatGuests,
					fieldScope: fieldRoot,
				});
			} else if (childrenInput instanceof HTMLInputElement) {
				childrenInput.addEventListener('input', formatGuests);
				childrenInput.addEventListener('change', formatGuests);
			}
			if (adultsInput instanceof HTMLInputElement) {
				adultsInput.addEventListener('input', formatGuests);
				adultsInput.addEventListener('change', formatGuests);
			}
		}

		fieldRoot.querySelectorAll('.bec-search-form__stepper').forEach(function (stepper) {
			var targetName = stepper.getAttribute('data-bec-stepper-for');
			var input = targetName ? fieldRoot.querySelector('[name="' + targetName.replace(/"/g, '\\"') + '"]') : null;
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
