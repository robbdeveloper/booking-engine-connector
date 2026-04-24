/**
 * Mobile booking summary: bottom bar + slide-in panel.
 */
(function () {
	function findPanel(root, openBtn) {
		var id = openBtn.getAttribute('aria-controls');
		if (!id) {
			return null;
		}
		return root.querySelector('#' + id) || document.getElementById(id);
	}

	function init(root) {
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

	var list = document.querySelectorAll('[data-bec-booking-summary]');
	for (var i = 0; i < list.length; i++) {
		init(list[i]);
	}
})();
