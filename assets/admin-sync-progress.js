/**
 * Live progress UI for manual full sync (Sync admin page).
 */
(function () {
	'use strict';

	var cfg = window.becSyncProgress;
	if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.syncNonce) {
		return;
	}

	var form = document.getElementById('bec-sync-all-form');
	var panel = document.getElementById('bec-sync-progress');
	var statusEl = document.getElementById('bec-sync-progress-status');
	var logEl = document.getElementById('bec-sync-progress-log');

	if (!form || !panel) {
		return;
	}

	function makeRunId() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return (
			'bec-' +
			Date.now().toString(36) +
			'-' +
			Math.random().toString(36).slice(2, 12)
		);
	}

	function pollUrl(runId) {
		var u = new URL(cfg.ajaxUrl, window.location.origin);
		u.searchParams.set('action', 'bec_sync_progress_poll');
		u.searchParams.set('nonce', cfg.nonce);
		u.searchParams.set('run_id', runId);
		return u.toString();
	}

	function parseWpJson(text) {
		text = text.replace(/^\uFEFF/, '').trim();
		var body;
		try {
			body = JSON.parse(text);
		} catch (ignore) {
			throw new Error('not json');
		}
		if (!body || typeof body.success === 'undefined') {
			throw new Error('bad json shape');
		}
		return body;
	}

	form.addEventListener('submit', function (e) {
		if (form.getAttribute('data-bec-sync-fallback') === '1') {
			return;
		}

		e.preventDefault();

		var runId = makeRunId();

		panel.hidden = false;
		panel.style.display = 'block';
		if (statusEl) {
			statusEl.textContent = '';
		}
		if (logEl) {
			logEl.textContent = '';
		}

		var submitBtn = form.querySelector('#bec-sync-all-submit, [type="submit"]');
		if (submitBtn) {
			submitBtn.disabled = true;
		}

		var stopped = false;
		var pollTimer = window.setInterval(function () {
			if (stopped) {
				return;
			}
			fetch(pollUrl(runId), {
				method: 'GET',
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (body) {
					if (!body || !body.success || !body.data) {
						return;
					}
					var d = body.data;
					if (statusEl) {
						var msg = d.message || '';
						var cur = typeof d.current === 'number' ? d.current : 0;
						var tot = typeof d.total === 'number' ? d.total : 0;
						if (tot > 0) {
							msg =
								msg +
								' (' +
								cur +
								'/' +
								tot +
								')';
						}
						statusEl.textContent = msg;
					}
					if (logEl && Array.isArray(d.lines)) {
						logEl.textContent = d.lines.join('\n');
						logEl.scrollTop = logEl.scrollHeight;
					}
					if (d.status === 'done') {
						stopped = true;
						window.clearInterval(pollTimer);
					}
				})
				.catch(function () {});
		}, 400);

		var syncBody = new FormData();
		syncBody.append('action', 'bec_sync_run_all');
		syncBody.append('sync_nonce', cfg.syncNonce);
		syncBody.append('bec_sync_run_id', runId);

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			body: syncBody,
			credentials: 'same-origin',
		})
			.then(function (r) {
				return r.text().then(function (text) {
					return parseWpJson(text);
				});
			})
			.then(function (body) {
				stopped = true;
				window.clearInterval(pollTimer);
				if (submitBtn) {
					submitBtn.disabled = false;
				}
				if (!body.success) {
					var err =
						body.data && body.data.message
							? String(body.data.message)
							: 'Sync failed.';
					if (statusEl) {
						statusEl.textContent = err;
					}
					return;
				}
				if (body && body.data && body.data.result && statusEl) {
					var res = body.data.result;
					var tail =
						'Created ' +
						(res.created || 0) +
						', updated ' +
						(res.updated || 0) +
						', skipped ' +
						(res.skipped || 0) +
						'.';
					if (res.errors && res.errors.length) {
						tail += ' ' + res.errors.join(' ');
					}
					statusEl.textContent = tail;
				}
			})
			.catch(function () {
				stopped = true;
				window.clearInterval(pollTimer);
				if (submitBtn) {
					submitBtn.disabled = false;
				}
				if (statusEl) {
					statusEl.textContent =
						'Sync request failed or returned an unexpected response. Try again or disable JavaScript to use the standard sync.';
				}
			});
	});
})();
