/**
 * Booking Engine Connector — enhanced search form (popovers, drawers, summaries).
 */
(function () {
	'use strict';

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

		var adultsInput = form.querySelector('[name="bec_adults"]');
		var childrenInput = form.querySelector('[name="bec_children"]');
		var guestSummary = form.querySelector('[data-bec-guest-summary]');

		function syncChildAgeRows() {
			var n = 0;
			if (childrenInput instanceof HTMLInputElement) {
				n = Math.max(0, parseInt(childrenInput.value, 10) || 0);
			}
			var rows = form.querySelectorAll('[data-bec-child-age-index]');
			rows.forEach(function (row) {
				var idx = parseInt(row.getAttribute('data-bec-child-age-index') || '0', 10);
				var on = idx < n;
				var inp = row.querySelector('input, select');
				row.hidden = !on;
				if (inp instanceof HTMLSelectElement) {
					inp.disabled = !on;
					if (!on) {
						inp.selectedIndex = 0;
					}
				} else if (inp instanceof HTMLInputElement) {
					inp.disabled = !on;
					if (!on) {
						inp.value = '';
					}
				}
			});
		}

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

		function formatGuests() {
			if (!guestSummary) {
				return;
			}
			var a = adultsInput instanceof HTMLInputElement ? Math.max(1, parseInt(adultsInput.value, 10) || 1) : 1;
			var c = childrenInput instanceof HTMLInputElement ? Math.max(0, parseInt(childrenInput.value, 10) || 0) : 0;
			if (adultsInput instanceof HTMLInputElement && (!adultsInput.value || parseInt(adultsInput.value, 10) < 1)) {
				adultsInput.value = '1';
			}
			var cfg = typeof window.becSearchForm === 'object' && window.becSearchForm ? window.becSearchForm : {};
			var tplOne = cfg.strAdultsOne || '%d adult';
			var tplMany = cfg.strAdultsMany || '%d adults';
			var tplKids = cfg.strWithChildren || '%1$d adults · %2$d children';
			if (c === 0) {
				guestSummary.textContent = sprintfPhpStyle(a === 1 ? tplOne : tplMany, a);
			} else {
				guestSummary.textContent = sprintfPhpStyle(tplKids, a, c);
			}
		}

		if (adultsInput instanceof HTMLInputElement) {
			adultsInput.addEventListener('input', function () {
				syncChildAgeRows();
				formatGuests();
			});
			adultsInput.addEventListener('change', formatGuests);
		}
		if (childrenInput instanceof HTMLInputElement) {
			childrenInput.addEventListener('input', function () {
				syncChildAgeRows();
				formatGuests();
			});
			childrenInput.addEventListener('change', function () {
				syncChildAgeRows();
				formatGuests();
			});
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

		syncChildAgeRows();
		formatGuests();

		form.addEventListener('submit', function () {
			syncChildAgeRows();
			if (adultsInput instanceof HTMLInputElement) {
				var av = parseInt(adultsInput.value, 10);
				if (!av || av < 1) {
					adultsInput.value = '1';
				}
			}
		});
	}

	document.querySelectorAll('form.bec-search-form--enhanced').forEach(initEnhancedForm);
})();
