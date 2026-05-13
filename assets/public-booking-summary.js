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
			if (!el || !el.closest) {
				return;
			}
			var btn = el.closest('[data-bec-submit-search-form]');
			if (!btn || !root.contains(btn)) {
				return;
			}
			var fid = btn.getAttribute('data-bec-submit-search-form');
			if (!fid) {
				return;
			}
			var form = document.getElementById(fid);
			if (!form || form.tagName !== 'FORM') {
				return;
			}
			e.preventDefault();
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit();
			} else {
				form.submit();
			}
		});
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
		initSearchFormSubmit(list[n]);
		initRateSwitch(list[n]);
	}
})();
