/**
 * Booking Engine Connector — unit filters: amenities multi-select enhancement.
 *
 * Progressive-enhancement layer over the markup emitted by
 * `UnitFilterShortcodeRenderer::renderAmenitiesField()`. Real `<input type="checkbox">`
 * controls are kept under the panel so GET filtering and no-JS fallback remain intact.
 *
 * Amenities — desktop: trigger opens popover (no backdrop); mobile: trigger + bottom sheet.
 * Order / rooms / bathrooms — desktop: value box opens dropdown; mobile: trigger + bottom sheet.
 */
(function () {
	'use strict';

	var READY_CLASS = 'bec-unit-filters__field--amenities-ready';
	var OPEN_CLASS = 'bec-unit-filters__field--amenities-open';
	var MOBILE_QUERY = '(max-width: 639px)';

	function getL10n() {
		return typeof window.becUnitFilters === 'object' && window.becUnitFilters
			? window.becUnitFilters
			: {};
	}

	/**
	 * @param {string}   fmt
	 * @param {number[]} nums
	 */
	function formatInts(fmt, nums) {
		if (!fmt) {
			return nums.length ? String(nums[0]) : '';
		}
		var indexed = fmt.replace(/%(\d+)\$d/g, function (_, idx) {
			var i = parseInt(idx, 10) - 1;
			return typeof nums[i] === 'number' ? String(nums[i]) : '';
		});
		var cursor = 0;
		return indexed.replace(/%d/g, function () {
			var v = typeof nums[cursor] === 'number' ? String(nums[cursor]) : '';
			cursor++;
			return v;
		});
	}

	/**
	 * @param {string} fmt
	 * @param {string} value
	 */
	function formatString(fmt, value) {
		if (!fmt) {
			return value;
		}
		return fmt.replace(/%s/g, value);
	}

	function isMobile(mq) {
		return mq.matches;
	}

	/**
	 * @param {HTMLElement} root
	 */
	function initAmenitiesField(root) {
		if (!root || root.getAttribute('data-bec-amenities-init') === '1') {
			return;
		}
		root.setAttribute('data-bec-amenities-init', '1');

		var cfg = getL10n();
		var placeholder = cfg.strAmenitiesPlaceholder || 'Pick desired amenities';
		var selectedOneFmt = cfg.strAmenitiesSelectedOne || '%d of %d selected';
		var selectedManyFmt = cfg.strAmenitiesSelectedMany || '%d of %d selected';

		var trigger = root.querySelector('[data-bec-amenities-trigger]');
		var triggerText = root.querySelector('[data-bec-amenities-trigger-text]');
		var panel = root.querySelector('[data-bec-amenities-panel]');
		var backdrop = root.querySelector('[data-bec-amenities-backdrop]');
		var list = root.querySelector('[data-bec-amenities-list]');
		var clearBtn = root.querySelector('[data-bec-amenities-clear]');
		var doneBtn = root.querySelector('[data-bec-amenities-done]');
		var closeBtn = root.querySelector('[data-bec-amenities-close]');

		if (!trigger || !panel || !list) {
			return;
		}

		var form = root.closest('form');
		var mqSheet = window.matchMedia(MOBILE_QUERY);

		/** @type {HTMLInputElement[]} */
		var checkboxes = Array.prototype.slice.call(
			list.querySelectorAll('input[type="checkbox"]')
		);
		var totalCount = checkboxes.length;

		root.classList.add(READY_CLASS);

		function setExpanded(expanded) {
			trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		}

		function focusOpener() {
			trigger.focus();
		}

		function getChecked() {
			return checkboxes.filter(function (cb) {
				return cb.checked;
			});
		}

		function updateTriggerLabel() {
			if (!triggerText) {
				return;
			}
			var n = getChecked().length;
			if (n === 0) {
				triggerText.textContent = placeholder;
				return;
			}
			var fmt = n === 1 ? selectedOneFmt : selectedManyFmt;
			triggerText.textContent = formatInts(fmt, [n, totalCount]);
		}

		function syncUi() {
			updateTriggerLabel();
		}

		function setBodyScrollLock(lock) {
			if (lock && isMobile(mqSheet)) {
				document.body.classList.add('bec-unit-filters__body-lock');
			} else {
				document.body.classList.remove('bec-unit-filters__body-lock');
			}
		}

		function setBackdropVisible(visible) {
			if (!backdrop) {
				return;
			}
			if (visible && isMobile(mqSheet)) {
				backdrop.removeAttribute('hidden');
			} else {
				backdrop.setAttribute('hidden', '');
			}
		}

		function isOpen() {
			return root.classList.contains(OPEN_CLASS);
		}

		function openPanel() {
			if (isOpen()) {
				return;
			}
			root.classList.add(OPEN_CLASS);
			setExpanded(true);
			setBackdropVisible(true);
			setBodyScrollLock(true);
		}

		function closePanel() {
			if (!isOpen()) {
				return;
			}
			root.classList.remove(OPEN_CLASS);
			setExpanded(false);
			setBackdropVisible(false);
			setBodyScrollLock(false);
		}

		function togglePanel() {
			if (isOpen()) {
				closePanel();
			} else {
				openPanel();
			}
		}

		trigger.addEventListener('click', function (ev) {
			ev.preventDefault();
			togglePanel();
		});

		if (backdrop) {
			backdrop.addEventListener('click', function () {
				if (isMobile(mqSheet)) {
					closePanel();
				}
			});
		}

		if (doneBtn) {
			doneBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				focusOpener();
			});
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				focusOpener();
			});
		}

		if (clearBtn) {
			clearBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				var changed = false;
				checkboxes.forEach(function (cb) {
					if (cb.checked) {
						cb.checked = false;
						changed = true;
					}
				});
				if (changed) {
					syncUi();
				}
			});
		}

		list.addEventListener('change', function (ev) {
			var t = ev.target;
			if (t && t instanceof HTMLInputElement && t.type === 'checkbox') {
				syncUi();
			}
		});

		document.addEventListener('click', function (ev) {
			if (!isOpen() || isMobile(mqSheet)) {
				return;
			}
			var t = ev.target;
			if (!(t instanceof Node)) {
				return;
			}
			if (root.contains(t)) {
				return;
			}
			closePanel();
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && isOpen()) {
				ev.stopPropagation();
				closePanel();
				focusOpener();
			}
		});

		if (form) {
			form.addEventListener('submit', function () {
				closePanel();
			});
		}

		function handleMqChange() {
			if (isOpen()) {
				setBackdropVisible(true);
				setBodyScrollLock(true);
			} else {
				setBackdropVisible(false);
				setBodyScrollLock(false);
			}
		}

		if (typeof mqSheet.addEventListener === 'function') {
			mqSheet.addEventListener('change', handleMqChange);
		} else if (typeof mqSheet.addListener === 'function') {
			mqSheet.addListener(handleMqChange);
		}

		syncUi();
	}

	/**
	 * @param {HTMLElement} root
	 */
	function initSelectPicker(root) {
		if (!root || root.getAttribute('data-bec-select-init') === '1') {
			return;
		}
		root.setAttribute('data-bec-select-init', '1');

		var cfg = getL10n();
		var anyLabel = cfg.strFilterAny || 'Any';

		var nativeSelect = root.querySelector('[data-bec-picker-native]');
		var trigger = root.querySelector('[data-bec-picker-trigger]');
		var triggerText = root.querySelector('[data-bec-picker-trigger-text]');
		var valueBox = root.querySelector('[data-bec-picker-value]');
		var valueText = root.querySelector('[data-bec-picker-value-text]');
		var panel = root.querySelector('[data-bec-picker-panel]');
		var backdrop = root.querySelector('[data-bec-picker-backdrop]');
		var list = root.querySelector('[data-bec-picker-list]');
		var doneBtn = root.querySelector('[data-bec-picker-done]');
		var closeBtn = root.querySelector('[data-bec-picker-close]');

		if (!nativeSelect || !trigger || !panel || !list) {
			return;
		}

		var PICKER_READY = 'bec-unit-filters__field--picker-ready';
		var PICKER_OPEN = 'bec-unit-filters__field--picker-open';

		var form = root.closest('form');
		var mqSheet = window.matchMedia(MOBILE_QUERY);

		/** @type {HTMLInputElement[]} */
		var radios = Array.prototype.slice.call(
			list.querySelectorAll('input[type="radio"]')
		);

		root.classList.add(PICKER_READY);

		function getOpeners() {
			/** @type {HTMLElement[]} */
			var openers = [trigger];
			if (valueBox) {
				openers.push(valueBox);
			}
			return openers;
		}

		function setExpanded(expanded) {
			getOpeners().forEach(function (el) {
				el.setAttribute('aria-expanded', expanded ? 'true' : 'false');
			});
		}

		function focusOpener() {
			if (isMobile(mqSheet)) {
				trigger.focus();
			} else if (valueBox) {
				valueBox.focus();
			} else {
				trigger.focus();
			}
		}

		function labelForValue(value) {
			var match = radios.find(function (r) {
				return r.value === value;
			});
			if (match) {
				return match.getAttribute('data-label') || match.value || anyLabel;
			}
			return anyLabel;
		}

		function getSelectedValue() {
			return nativeSelect.value;
		}

		function syncRadiosFromSelect() {
			var val = getSelectedValue();
			radios.forEach(function (r) {
				r.checked = r.value === val;
			});
		}

		function updateLabels() {
			var text = labelForValue(getSelectedValue());
			if (triggerText) {
				triggerText.textContent = text;
			}
			if (valueText) {
				valueText.textContent = text;
			}
			if (valueBox) {
				if (getSelectedValue() === '') {
					valueBox.classList.remove('bec-unit-filters__picker-value--selected');
				} else {
					valueBox.classList.add('bec-unit-filters__picker-value--selected');
				}
			}
		}

		function setValue(value) {
			nativeSelect.value = value;
			syncRadiosFromSelect();
			updateLabels();
			nativeSelect.dispatchEvent(new Event('change', { bubbles: true }));
		}

		function setBodyScrollLock(lock) {
			if (lock && isMobile(mqSheet)) {
				document.body.classList.add('bec-unit-filters__body-lock');
			} else {
				document.body.classList.remove('bec-unit-filters__body-lock');
			}
		}

		function setBackdropVisible(visible) {
			if (!backdrop) {
				return;
			}
			if (visible && isMobile(mqSheet)) {
				backdrop.removeAttribute('hidden');
			} else {
				backdrop.setAttribute('hidden', '');
			}
		}

		function isOpen() {
			return root.classList.contains(PICKER_OPEN);
		}

		function openPanel() {
			if (isOpen()) {
				return;
			}
			syncRadiosFromSelect();
			root.classList.add(PICKER_OPEN);
			setExpanded(true);
			setBackdropVisible(true);
			setBodyScrollLock(true);
		}

		function closePanel() {
			if (!isOpen()) {
				return;
			}
			root.classList.remove(PICKER_OPEN);
			setExpanded(false);
			setBackdropVisible(false);
			setBodyScrollLock(false);
		}

		function togglePanel() {
			if (isOpen()) {
				closePanel();
			} else {
				openPanel();
			}
		}

		trigger.addEventListener('click', function (ev) {
			ev.preventDefault();
			togglePanel();
		});

		if (valueBox) {
			valueBox.addEventListener('click', function (ev) {
				if (isMobile(mqSheet)) {
					return;
				}
				ev.preventDefault();
				togglePanel();
			});

			valueBox.addEventListener('keydown', function (ev) {
				if (isMobile(mqSheet)) {
					return;
				}
				if (ev.key === 'Enter' || ev.key === ' ') {
					ev.preventDefault();
					togglePanel();
				}
			});
		}

		if (backdrop) {
			backdrop.addEventListener('click', function () {
				if (isMobile(mqSheet)) {
					closePanel();
				}
			});
		}

		if (doneBtn) {
			doneBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				focusOpener();
			});
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				focusOpener();
			});
		}

		list.addEventListener('change', function (ev) {
			var t = ev.target;
			if (!(t instanceof HTMLInputElement) || t.type !== 'radio') {
				return;
			}
			setValue(t.value);
			if (!isMobile(mqSheet)) {
				closePanel();
				focusOpener();
			}
		});

		document.addEventListener('click', function (ev) {
			if (!isOpen() || isMobile(mqSheet)) {
				return;
			}
			var t = ev.target;
			if (!(t instanceof Node)) {
				return;
			}
			if (root.contains(t)) {
				return;
			}
			closePanel();
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && isOpen()) {
				ev.stopPropagation();
				closePanel();
				focusOpener();
			}
		});

		if (form) {
			form.addEventListener('submit', function () {
				closePanel();
			});
		}

		function handleMqChange() {
			if (isOpen()) {
				setBackdropVisible(true);
				setBodyScrollLock(true);
			} else {
				setBackdropVisible(false);
				setBodyScrollLock(false);
			}
			updateLabels();
		}

		if (typeof mqSheet.addEventListener === 'function') {
			mqSheet.addEventListener('change', handleMqChange);
		} else if (typeof mqSheet.addListener === 'function') {
			mqSheet.addListener(handleMqChange);
		}

		syncRadiosFromSelect();
		updateLabels();
	}

	function initInDocument(scope) {
		var ctx = scope && scope.querySelectorAll ? scope : document;
		ctx.querySelectorAll('[data-bec-amenities-root]').forEach(function (root) {
			initAmenitiesField(root);
		});
		ctx.querySelectorAll('[data-bec-select-root]').forEach(function (root) {
			initSelectPicker(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initInDocument(document);
		});
	} else {
		initInDocument(document);
	}

	if (typeof window !== 'undefined') {
		window.becUnitFiltersInit = initInDocument;
	}
})();
