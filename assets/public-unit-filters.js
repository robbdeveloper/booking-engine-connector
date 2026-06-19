/**
 * Booking Engine Connector — unit filters: amenities multi-select enhancement.
 *
 * Progressive-enhancement layer over the markup emitted by
 * `UnitFilterShortcodeRenderer::renderAmenitiesField()`. Real `<input type="checkbox">`
 * controls are kept under the panel so GET filtering and no-JS fallback remain intact.
 *
 * Amenities — desktop: trigger opens popover (no backdrop); mobile: trigger + bottom sheet.
 * Order / rooms / bathrooms — desktop: value box opens dropdown; mobile: trigger + bottom sheet.
 * Mobile hub — Filter button opens a body-mounted drawer listing filter rows; each row opens the existing field drawer.
 */
(function () {
	'use strict';

	var READY_CLASS = 'bec-unit-filters__field--amenities-ready';
	var OPEN_CLASS = 'bec-unit-filters__field--amenities-open';
	var MOBILE_QUERY = '(max-width: 639px)';
	var HUB_READY_CLASS = 'bec-unit-filters--mobile-hub-ready';
	var HUB_OPEN_CLASS = 'bec-unit-filters--hub-open';

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
	 * @returns {HTMLElement | null}
	 */
	function getFieldPanelMount(root) {
		if (!root) {
			return null;
		}
		var panelRootId = root.getAttribute('data-bec-field-panel-root');
		if (!panelRootId) {
			return null;
		}
		var safeId =
			typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
				? CSS.escape(panelRootId)
				: panelRootId.replace(/"/g, '\\"');
		return document.querySelector(
			'.bec-unit-filters__field-panel-mount[data-bec-field-panel-root="' + safeId + '"]'
		);
	}

	/**
	 * @param {HTMLElement} root
	 * @param {string} mountClass
	 * @param {string} backdropSelector
	 * @param {string} panelSelector
	 * @param {string} portaledFlag
	 * @returns {{ mount: HTMLElement, backdrop: HTMLElement | null, panel: HTMLElement } | null}
	 */
	function portalFieldPanel(root, mountClass, backdropSelector, panelSelector, portaledFlag) {
		if (!root) {
			return null;
		}

		var mqSheet = window.matchMedia(MOBILE_QUERY);
		if (!isMobile(mqSheet)) {
			return null;
		}

		var existingMount = getFieldPanelMount(root);
		if (existingMount) {
			return {
				mount: existingMount,
				backdrop: existingMount.querySelector(backdropSelector),
				panel: existingMount.querySelector(panelSelector),
			};
		}

		if (root.getAttribute(portaledFlag) === '1') {
			return null;
		}

		var backdrop = root.querySelector(backdropSelector);
		var panel = root.querySelector(panelSelector);
		if (!panel) {
			return null;
		}

		var panelRootId =
			root.getAttribute('data-bec-field-panel-root') ||
			'bec_field_panel_' + Math.random().toString(36).slice(2, 10);
		root.setAttribute('data-bec-field-panel-root', panelRootId);

		var mount = document.createElement('div');
		mount.className = 'bec-unit-filters bec-unit-filters__field-panel-mount ' + mountClass;
		mount.setAttribute('data-bec-field-panel-mount', '');
		mount.setAttribute('data-bec-field-panel-root', panelRootId);
		if (backdrop) {
			mount.appendChild(backdrop);
		}
		mount.appendChild(panel);
		document.body.appendChild(mount);

		root.setAttribute(portaledFlag, '1');
		return {
			mount: mount,
			backdrop: backdrop,
			panel: panel,
		};
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
		var placeholder =
			root.getAttribute('data-bec-amenities-placeholder') ||
			cfg.strAmenitiesPlaceholder ||
			'Pick desired amenities';
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

		var portaled = portalFieldPanel(
			root,
			'bec-unit-filters__amenities-panel-mount',
			'[data-bec-amenities-backdrop]',
			'[data-bec-amenities-panel]',
			'data-bec-amenities-portaled'
		);
		var panelMount = portaled ? portaled.mount : null;
		if (portaled) {
			backdrop = portaled.backdrop;
			panel = portaled.panel;
		}

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
				root.classList.remove('bec-unit-filters__amenities-trigger--has-selection');
				return;
			}
			root.classList.add('bec-unit-filters__amenities-trigger--has-selection');
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
			if (panelMount) {
				panelMount.classList.add(OPEN_CLASS);
			}
			setExpanded(true);
			setBackdropVisible(true);
			setBodyScrollLock(true);
		}

		function closePanel() {
			if (!isOpen()) {
				return;
			}
			root.classList.remove(OPEN_CLASS);
			if (panelMount) {
				panelMount.classList.remove(OPEN_CLASS);
			}
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
					if (form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
						form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					}
				}
			});
		}

		if (doneBtn) {
			doneBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
				focusOpener();
			});
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
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
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
				focusOpener();
			}
		});

		if (form) {
			form.addEventListener('submit', function () {
				closePanel();
			});
		}

		function handleMqChange() {
			if (isMobile(mqSheet) && !root.getAttribute('data-bec-amenities-portaled')) {
				var portaledOnResize = portalFieldPanel(
					root,
					'bec-unit-filters__amenities-panel-mount',
					'[data-bec-amenities-backdrop]',
					'[data-bec-amenities-panel]',
					'data-bec-amenities-portaled'
				);
				if (portaledOnResize) {
					panelMount = portaledOnResize.mount;
					backdrop = portaledOnResize.backdrop;
					panel = portaledOnResize.panel;
				}
			}
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
		var fieldPlaceholder = root.getAttribute('data-bec-picker-placeholder') || '';

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

		var portaledPicker = portalFieldPanel(
			root,
			'bec-unit-filters__picker-panel-mount',
			'[data-bec-picker-backdrop]',
			'[data-bec-picker-panel]',
			'data-bec-picker-portaled'
		);
		var pickerMount = portaledPicker ? portaledPicker.mount : null;
		if (portaledPicker) {
			backdrop = portaledPicker.backdrop;
			panel = portaledPicker.panel;
		}

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
			if (value === '' && fieldPlaceholder) {
				return fieldPlaceholder;
			}
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
			if (trigger) {
				if (getSelectedValue() === '') {
					trigger.classList.remove('bec-unit-filters__picker-trigger--selected');
				} else {
					trigger.classList.add('bec-unit-filters__picker-trigger--selected');
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
			if (pickerMount) {
				pickerMount.classList.add(PICKER_OPEN);
			}
			setExpanded(true);
			setBackdropVisible(true);
			setBodyScrollLock(true);
		}

		function closePanel() {
			if (!isOpen()) {
				return;
			}
			root.classList.remove(PICKER_OPEN);
			if (pickerMount) {
				pickerMount.classList.remove(PICKER_OPEN);
			}
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
					if (form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
						form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					}
				}
			});
		}

		if (doneBtn) {
			doneBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
				focusOpener();
			});
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', function (ev) {
				ev.preventDefault();
				closePanel();
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
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
				if (isMobile(mqSheet) && form && form.getAttribute('data-bec-filter-hub-flow') === '1') {
					form.dispatchEvent(new CustomEvent('bec:unit-filters-reopen-hub'));
					return;
				}
				focusOpener();
			}
		});

		if (form) {
			form.addEventListener('submit', function () {
				closePanel();
			});
		}

		function handleMqChange() {
			if (isMobile(mqSheet) && !root.getAttribute('data-bec-picker-portaled')) {
				var portaledOnResize = portalFieldPanel(
					root,
					'bec-unit-filters__picker-panel-mount',
					'[data-bec-picker-backdrop]',
					'[data-bec-picker-panel]',
					'data-bec-picker-portaled'
				);
				if (portaledOnResize) {
					pickerMount = portaledOnResize.mount;
					backdrop = portaledOnResize.backdrop;
					panel = portaledOnResize.panel;
				}
			}
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

	/**
	 * @param {HTMLFormElement} form
	 * @returns {HTMLElement | null}
	 */
	function getHubMount(form) {
		if (!form || !form.id) {
			return null;
		}
		var safeId =
			typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
				? CSS.escape(form.id)
				: form.id.replace(/"/g, '\\"');
		return document.querySelector(
			'.bec-unit-filters__hub-mount[data-bec-filter-hub-form-id="' + safeId + '"]'
		);
	}

	/**
	 * Move hub backdrop and panel to document.body (mirrors booking-summary drawer mount).
	 *
	 * @param {HTMLFormElement} form
	 * @returns {HTMLElement | null}
	 */
	function portalFilterHub(form) {
		if (!form) {
			return null;
		}
		var existing = getHubMount(form);
		if (existing) {
			return existing;
		}
		if (form.getAttribute('data-bec-filter-hub-portaled') === '1') {
			return getHubMount(form);
		}

		var hub = form.querySelector('[data-bec-filter-hub]');
		if (!hub) {
			return null;
		}

		var backdrop = hub.querySelector('[data-bec-filter-hub-backdrop]');
		var panel = hub.querySelector('[data-bec-filter-hub-panel]');
		if (!panel) {
			return null;
		}

		var mount = document.createElement('div');
		mount.className = 'bec-unit-filters bec-unit-filters__hub-mount';
		mount.setAttribute('data-bec-filter-hub-mount', '');
		mount.setAttribute('data-bec-filter-hub-form-id', form.id);

		if (backdrop) {
			mount.appendChild(backdrop);
		}
		mount.appendChild(panel);
		document.body.appendChild(mount);

		form.setAttribute('data-bec-filter-hub-portaled', '1');
		return mount;
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function initMobileFilterHub(form) {
		if (!form || form.getAttribute('data-bec-filter-hub-init') === '1') {
			return;
		}

		var hub = form.querySelector('[data-bec-filter-hub]');
		var hubTrigger = hub ? hub.querySelector('[data-bec-filter-hub-trigger]') : null;
		var hubBackdrop = hub ? hub.querySelector('[data-bec-filter-hub-backdrop]') : null;
		var hubPanel = hub ? hub.querySelector('[data-bec-filter-hub-panel]') : null;
		var hubClose = hub ? hub.querySelector('[data-bec-filter-hub-close]') : null;
		var hubRows = hub ? hub.querySelectorAll('[data-bec-filter-hub-row]') : null;
		var hubBadge = hub ? hub.querySelector('[data-bec-filter-hub-badge]') : null;

		if (!hub || !hubTrigger || !hubPanel || !hubRows || hubRows.length === 0) {
			return;
		}

		form.setAttribute('data-bec-filter-hub-init', '1');

		var mount = portalFilterHub(form);
		var mqSheet = window.matchMedia(MOBILE_QUERY);

		if (mount) {
			hubBackdrop = mount.querySelector('[data-bec-filter-hub-backdrop]');
			hubPanel = mount.querySelector('[data-bec-filter-hub-panel]');
			hubClose = mount.querySelector('[data-bec-filter-hub-close]');
			hubRows = mount.querySelectorAll('[data-bec-filter-hub-row]');
		}

		form.classList.add(HUB_READY_CLASS);

		function setBodyScrollLock(lock) {
			if (lock && isMobile(mqSheet)) {
				document.body.classList.add('bec-unit-filters__body-lock');
			} else if (!isChildPanelOpen()) {
				document.body.classList.remove('bec-unit-filters__body-lock');
			}
		}

		function isChildPanelOpen() {
			return !!form.querySelector(
				'.bec-unit-filters__field--amenities-open, .bec-unit-filters__field--picker-open'
			);
		}

		function isHubOpen() {
			return form.classList.contains(HUB_OPEN_CLASS);
		}

		function setHubExpanded(expanded) {
			hubTrigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		}

		function setHubBackdropVisible(visible) {
			if (!hubBackdrop) {
				return;
			}
			if (visible && isMobile(mqSheet)) {
				hubBackdrop.removeAttribute('hidden');
			} else {
				hubBackdrop.setAttribute('hidden', '');
			}
		}

		function setHubOpenState(open) {
			form.classList.toggle(HUB_OPEN_CLASS, open);
			if (mount) {
				mount.classList.toggle(HUB_OPEN_CLASS, open);
			}
		}

		function openHub() {
			if (!isMobile(mqSheet) || isHubOpen()) {
				return;
			}
			syncHubRows();
			setHubOpenState(true);
			setHubExpanded(true);
			setHubBackdropVisible(true);
			setBodyScrollLock(true);
		}

		function closeHub(clearFlow) {
			if (!isHubOpen()) {
				return;
			}
			setHubOpenState(false);
			setHubExpanded(false);
			setHubBackdropVisible(false);
			if (clearFlow) {
				form.removeAttribute('data-bec-filter-hub-flow');
			}
			if (!isChildPanelOpen()) {
				setBodyScrollLock(false);
			}
		}

		function toggleHub() {
			if (isHubOpen()) {
				closeHub(true);
			} else {
				openHub();
			}
		}

		/**
		 * @param {string} slug
		 * @returns {HTMLElement | null}
		 */
		function fieldRootForSlug(slug) {
			return form.querySelector('.bec-unit-filters__field--' + slug);
		}

		/**
		 * @param {string} slug
		 * @returns {HTMLButtonElement | null}
		 */
		function fieldTriggerForSlug(slug) {
			var root = fieldRootForSlug(slug);
			if (!root) {
				return null;
			}
			if (slug === 'amenities') {
				return root.querySelector('[data-bec-amenities-trigger]');
			}
			return root.querySelector('[data-bec-picker-trigger]');
		}

		/**
		 * @param {string} slug
		 * @returns {string}
		 */
		function fieldValueForSlug(slug) {
			var root = fieldRootForSlug(slug);
			if (!root) {
				return '';
			}
			if (slug === 'amenities') {
				var amenitiesText = root.querySelector('[data-bec-amenities-trigger-text]');
				return amenitiesText ? amenitiesText.textContent || '' : '';
			}
			var pickerText = root.querySelector('[data-bec-picker-trigger-text]');
			return pickerText ? pickerText.textContent || '' : '';
		}

		function countActiveFilters() {
			var count = 0;

			form.querySelectorAll('[data-bec-select-root]').forEach(function (root) {
				var nativeSelect = root.querySelector('[data-bec-picker-native]');
				if (nativeSelect && nativeSelect.value !== '') {
					count++;
				}
			});

			form.querySelectorAll('[data-bec-amenities-root]').forEach(function (root) {
				var checked = root.querySelectorAll(
					'[data-bec-amenities-list] input[type="checkbox"]:checked'
				);
				if (checked.length > 0) {
					count++;
				}
			});

			return count;
		}

		function syncHubBadge() {
			if (!hubBadge) {
				return;
			}
			var n = countActiveFilters();
			if (n > 0) {
				hubBadge.textContent = String(n);
				hubBadge.removeAttribute('hidden');
			} else {
				hubBadge.setAttribute('hidden', '');
			}
		}

		function syncHubRows() {
			Array.prototype.forEach.call(hubRows, function (row) {
				var slug = row.getAttribute('data-bec-filter-target') || '';
				var valueEl = row.querySelector('[data-bec-filter-hub-row-value]');
				if (!slug || !valueEl) {
					return;
				}
				valueEl.textContent = fieldValueForSlug(slug);

				var root = fieldRootForSlug(slug);
				var isActive = false;
				if (slug === 'amenities' && root) {
					isActive = root.querySelectorAll(
						'[data-bec-amenities-list] input[type="checkbox"]:checked'
					).length > 0;
				} else if (root) {
					var select = root.querySelector('[data-bec-picker-native]');
					isActive = !!(select && select.value !== '');
				}
				row.classList.toggle('bec-unit-filters__hub-row--active', isActive);
			});
			syncHubBadge();
		}

		hubTrigger.addEventListener('click', function (ev) {
			ev.preventDefault();
			toggleHub();
		});

		if (hubBackdrop) {
			hubBackdrop.addEventListener('click', function () {
				if (isMobile(mqSheet)) {
					closeHub(true);
				}
			});
		}

		if (hubClose) {
			hubClose.addEventListener('click', function (ev) {
				ev.preventDefault();
				closeHub(true);
				hubTrigger.focus();
			});
		}

		Array.prototype.forEach.call(hubRows, function (row) {
			row.addEventListener('click', function (ev) {
				ev.preventDefault();
				if (!isMobile(mqSheet)) {
					return;
				}
				var slug = row.getAttribute('data-bec-filter-target') || '';
				var fieldTrigger = fieldTriggerForSlug(slug);
				if (!fieldTrigger) {
					return;
				}
				form.setAttribute('data-bec-filter-hub-flow', '1');
				closeHub(false);
				window.requestAnimationFrame(function () {
					fieldTrigger.click();
				});
			});
		});

		document.addEventListener('keydown', function (ev) {
			if (ev.key !== 'Escape' || !isHubOpen() || isChildPanelOpen()) {
				return;
			}
			ev.stopPropagation();
			closeHub(true);
			hubTrigger.focus();
		});

		form.addEventListener('change', function () {
			syncHubRows();
		});

		form.addEventListener('bec:unit-filters-fields-ready', function () {
			syncHubRows();
		});

		form.addEventListener('bec:unit-filters-reopen-hub', function () {
			if (!isMobile(mqSheet)) {
				return;
			}
			window.requestAnimationFrame(function () {
				openHub();
			});
		});

		form.addEventListener('submit', function () {
			form.removeAttribute('data-bec-filter-hub-flow');
			closeHub(false);
		});

		function handleMqChange() {
			if (!isMobile(mqSheet)) {
				form.removeAttribute('data-bec-filter-hub-flow');
				closeHub(false);
				setHubBackdropVisible(false);
				if (!isChildPanelOpen()) {
					document.body.classList.remove('bec-unit-filters__body-lock');
				}
			}
			syncHubRows();
		}

		if (typeof mqSheet.addEventListener === 'function') {
			mqSheet.addEventListener('change', handleMqChange);
		} else if (typeof mqSheet.addListener === 'function') {
			mqSheet.addListener(handleMqChange);
		}

		syncHubRows();
	}

	function initInDocument(scope) {
		var ctx = scope && scope.querySelectorAll ? scope : document;
		ctx.querySelectorAll('form[data-bec-unit-filters-form]').forEach(function (form) {
			initMobileFilterHub(form);
		});
		ctx.querySelectorAll('[data-bec-amenities-root]').forEach(function (root) {
			initAmenitiesField(root);
		});
		ctx.querySelectorAll('[data-bec-select-root]').forEach(function (root) {
			initSelectPicker(root);
		});
		ctx.querySelectorAll('form[data-bec-unit-filters-form]').forEach(function (form) {
			try {
				form.dispatchEvent(new CustomEvent('bec:unit-filters-fields-ready'));
			} catch (err) {
				syncHubRowsFallback(form);
			}
		});
	}

	/**
	 * @param {HTMLFormElement} form
	 */
	function syncHubRowsFallback(form) {
		var hub = form.querySelector('[data-bec-filter-hub]');
		var mount = getHubMount(form);
		var hubRows = mount
			? mount.querySelectorAll('[data-bec-filter-hub-row]')
			: hub
				? hub.querySelectorAll('[data-bec-filter-hub-row]')
				: [];
		Array.prototype.forEach.call(hubRows, function (row) {
			var slug = row.getAttribute('data-bec-filter-target') || '';
			var valueEl = row.querySelector('[data-bec-filter-hub-row-value]');
			if (!slug || !valueEl) {
				return;
			}
			var root = form.querySelector('.bec-unit-filters__field--' + slug);
			if (slug === 'amenities' && root) {
				var amenitiesText = root.querySelector('[data-bec-amenities-trigger-text]');
				if (amenitiesText) {
					valueEl.textContent = amenitiesText.textContent || '';
				}
			} else if (root) {
				var pickerText = root.querySelector('[data-bec-picker-trigger-text]');
				if (pickerText) {
					valueEl.textContent = pickerText.textContent || '';
				}
			}
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
