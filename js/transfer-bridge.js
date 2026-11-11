/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.CareCheckApi / CareCheckMessaging contract the synced
 * js/transfer.js expects, in CareCheck's own API envelope
 * ({success,data} / {success:false,error,message,context}).
 * App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && (j.ok === false || j.success === false))) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.CareCheckApi = {
		url(path) {
			return OC.generateUrl('/apps/carecheck' + path);
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	// One replace-text banner, same shape as carecheck-main.js notify() —
	// dedups by construction (toast-hygiene rule) and mirrors into the
	// assertive live region for errors.
	function banner(text, isError) {
		var el = document.querySelector('[data-cck-banner]');
		if (!el) {
			el = document.createElement('div');
			el.dataset.cckBanner = '1';
			el.className = 'cck-banner';
			var host = document.querySelector('[data-cck-app]');
			if (host) { host.prepend(el); }
		}
		el.setAttribute('role', isError ? 'alert' : 'status');
		el.classList.toggle('cck-banner--error', !!isError);
		el.textContent = text;
		el.hidden = false;
		clearTimeout(el._t);
		el._t = setTimeout(function () { el.textContent = ''; el.hidden = true; }, 6000);
		var lr = document.getElementById(isError ? 'cck-alert-region' : 'cck-live-region');
		if (lr) { lr.textContent = ''; setTimeout(function () { lr.textContent = text; }, 30); }
	}
	window.CareCheckMessaging = {
		alert(text) {
			banner(text, true);
			const el = document.getElementById('cck-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) {
			banner(text, false);
			const el = document.getElementById('cck-transfer-result-live');
			if (el) el.textContent = text;
		},
	};
})();
