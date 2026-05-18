/**
 * Live progress UI for manual full sync (Sync admin page).
 * Uses short admin-ajax "start" + "step" requests so each HTTP round-trip stays within PHP/proxy limits.
 */
(function () {
	'use strict';

	var cfg = window.becSyncProgress;
	if (!cfg || !cfg.ajaxUrl || !cfg.nonce || !cfg.syncNonce) {
		return;
	}

	function sprintfSyncedSummary(fmt, a1, a2, a3) {
		if (!fmt) {
			return '';
		}
		return fmt
			.replace(/%1\$d/g, String(a1))
			.replace(/%2\$d/g, String(a2))
			.replace(/%3\$d/g, String(a3));
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

	function showFinalSummary(res) {
		if (!res || !statusEl) {
			return;
		}
		var tmpl = cfg.syncResultSummary || '';
		var tail =
			tmpl ||
			'Created ' +
				(res.created || 0) +
				', updated ' +
				(res.updated || 0) +
				', skipped ' +
				(res.skipped || 0) +
				'.';
		if (tmpl) {
			tail = sprintfSyncedSummary(tmpl, res.created || 0, res.updated || 0, res.skipped || 0);
		}
		if (res.errors && res.errors.length) {
			tail += ' ' + res.errors.join(' ');
		}
		statusEl.textContent = tail;
	}

	function startSync(runId) {
		var b = new FormData();
		b.append('action', 'bec_sync_start_all');
		b.append('sync_nonce', cfg.syncNonce);
		b.append('bec_sync_run_id', runId);
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			body: b,
			credentials: 'same-origin',
		}).then(function (r) {
			return r.text().then(function (text) {
				return parseWpJson(text);
			});
		});
	}

	function stepSync(runId) {
		var b = new FormData();
		b.append('action', 'bec_sync_step_all');
		b.append('sync_nonce', cfg.syncNonce);
		b.append('bec_sync_run_id', runId);
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			body: b,
			credentials: 'same-origin',
		}).then(function (r) {
			return r.text().then(function (text) {
				return parseWpJson(text);
			});
		});
	}

	function finish(err, submitBtn, stoppedRef, pollTimer) {
		stoppedRef.v = true;
		if (pollTimer) {
			window.clearInterval(pollTimer);
		}
		if (submitBtn) {
			submitBtn.disabled = false;
		}
		if (err && statusEl) {
			statusEl.textContent = err;
		}
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

		var stoppedRef = { v: false };
		var pollTimer = window.setInterval(function () {
			if (stoppedRef.v) {
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
						stoppedRef.v = true;
						window.clearInterval(pollTimer);
					}
				})
				.catch(function () {});
		}, 400);

		startSync(runId)
			.then(function (body) {
				if (!body.success) {
					var err0 =
						body.data && body.data.message
							? String(body.data.message)
							: cfg.syncFailedGeneric || 'Sync failed.';
					finish(err0, submitBtn, stoppedRef, pollTimer);
					return;
				}
				function nextStep() {
					return stepSync(runId)
						.then(function (stepBody) {
							if (!stepBody.success) {
								var err1 =
									stepBody.data && stepBody.data.message
										? String(stepBody.data.message)
										: cfg.syncFailedGeneric || 'Sync failed.';
								finish(err1, submitBtn, stoppedRef, pollTimer);
								return;
							}
							var d = stepBody.data || {};
							if (d.done) {
								finish(null, submitBtn, stoppedRef, pollTimer);
								if (d.result) {
									showFinalSummary(d.result);
								}
								return;
							}
							return nextStep();
						})
						.catch(function () {
							finish(
								cfg.syncUnexpectedResponse ||
									'Sync request failed or returned an unexpected response. Try again or disable JavaScript to use the standard sync.',
								submitBtn,
								stoppedRef,
								pollTimer
							);
						});
				}
				return nextStep();
			})
			.catch(function () {
				finish(
					cfg.syncUnexpectedResponse ||
						'Sync request failed or returned an unexpected response. Try again or disable JavaScript to use the standard sync.',
					submitBtn,
					stoppedRef,
					pollTimer
				);
			});
	});
})();
