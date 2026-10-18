/* global OC, OCP */
(function () {
	'use strict';
	var api = function (path, opts) {
		return fetch(OC.generateUrl('/apps/carecheck' + path), Object.assign({
			headers: { 'requesttoken': OC.requestToken, 'Content-Type': 'application/json' },
		}, opts)).then(function (r) {
			var ct = r.headers.get('content-type') || '';
			if (ct.indexOf('json') === -1) { return r; } // download passthrough
			return r.json();
		}).catch(function () { return { success: false, message: tr('Connection failed — check the network and try again.') }; });
	};
	var tr = function (s) { return OC.L10N.translate('carecheck', s); };
	var esc = function (s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; };

	var FIELD_MSG = {
		required: function () { return tr('Required.'); },
		invalid: function () { return tr('Invalid value.'); },
		invalid_format: function () { return tr('Invalid format.'); },
		taken: function () { return tr('Already used.'); },
		too_short: function () { return tr('Too short.'); },
		untyped: function () { return tr('Use member:ID or uid:NAME.'); },
	};

	// Machine tokens must never reach the UI — every enum the API returns
	// has a localized label; unknown tokens fall back to the raw string.
	var NOTICE_KIND = {
		incident: 'Incident', supply_needed: 'Supply needed',
		plan_change_proposal: 'Plan change proposal', info: 'Info',
	};
	var ABSENCE_KIND = { sick: 'Sick', vacation: 'Vacation', other: 'Other' };
	var JOURNAL_TYPE = {
		manual_note: 'Manual note', visit_done: 'Visit completed', visit_rejected: 'Visit rejected',
		visit_corrected: 'Visit corrected', briefing_shown: 'Briefing shown', checkin: 'Check-in',
		stop_skipped: 'Stop skipped', group_session_open: 'Group session started',
		group_session_close: 'Group session ended', digest: 'Digest sent', export: 'Export created',
		photo_add: 'Photo added', photo_del: 'Photo deleted', voicenote_add: 'Voice note added',
		voicenote_transcribed: 'Voice note transcribed',
	};
	function tokenLabel(map, k) { return map[k] !== undefined ? tr(map[k]) : k; }

	// Shared styled confirm — destructive actions ask once via a real
	// <dialog> (localized buttons, Escape-safe), never window.confirm.
	function confirmAsk(text, okLabel) {
		var dlg = document.querySelector('[data-cck-confirm-dialog]');
		// ds-allow-native-fallback: <dialog> element absent on this page
		if (!dlg) { return Promise.resolve(window.confirm(text)); }
		dlg.querySelector('[data-cck-confirm-text]').textContent = text;
		var ok = dlg.querySelector('[data-cck-confirm-ok]');
		ok.textContent = okLabel || tr('Confirm');
		return new Promise(function (resolve) {
			var settled = false;
			var done = function (v) { if (settled) { return; } settled = true; dlg.close(); resolve(v); };
			dlg.querySelector('[data-cck-confirm-cancel]').onclick = function () { done(false); };
			ok.onclick = function () { done(true); };
			dlg.onkeydown = function (ev) { if (ev.key === 'Escape') { ev.preventDefault(); done(false); } };
			dlg.onclose = function () { done(false); };
			dlg._invoker = document.activeElement;
			dlg.showModal();
			ok.focus();
		});
	}

	// Canonical shared renderer (DS-7): server sends stable codes in `fields`,
	// we map them to localized text, CheckFieldErrors paints aria-invalid +
	// inline .cck-field-error + focuses the first offender + clears on edit.
	var feInstalled = false;
	function fe() {
		if (!feInstalled && window.CheckFieldErrors) {
			window.CheckFieldErrors.install({ prefix: 'cck' });
			feInstalled = true;
		}
		return window.CheckFieldErrors;
	}
	function reportForm(form, r) {
		if (fe()) { fe().clearFieldMarks(form); }
		var status = form.querySelector('.cck-form-status');
		if (!status) {
			status = document.createElement('div');
			status.className = 'cck-form-status';
			status.setAttribute('role', 'alert');
			form.prepend(status);
		}
		status.textContent = r.message || '';
		status.hidden = !r.message;
		var mapped = {};
		Object.keys(r.fields || {}).forEach(function (name) {
			var fn = FIELD_MSG[r.fields[name]];
			mapped[name] = fn ? fn() : tr('Invalid value.');
		});
		if (fe()) { fe().markValidationFields(mapped); }
	}

	// One-tap discipline: every submit/disclosure disables itself while the
	// request is in flight — gloved double-taps cannot fire twice.
	function withBusy(btn, fn) {
		if (!btn) { fn(); return; }
		btn.disabled = true;
		btn.setAttribute('aria-busy', 'true');
		var done = function () { btn.disabled = false; btn.removeAttribute('aria-busy'); };
		try { fn().then(done, done); } catch (e) { done(); throw e; }
	}
	function submitBtn(form) { return form.querySelector('button[type=submit]'); }

	function clearFormErrors(form) {
		if (fe()) { fe().clearFieldMarks(form); }
		var status = form.querySelector('.cck-form-status');
		if (status) { status.textContent = ''; status.hidden = true; }
	}

	// local calendar date — toISOString is UTC and would flip the board a
	// day early/late depending on the viewer's offset.
	function today() {
		var d = new Date();
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function fmtMin(m) { return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); }
	// ISO → locale datetime (de: 18.10.2026, 11:30); falls back to ISO slice
	function fmtTs(ts) {
		var d = new Date(ts);
		return isNaN(d) ? String(ts || '').slice(0, 16).replace('T', ' ')
			: d.toLocaleString(undefined, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
	}

	// one replace-text banner — dedups by construction (toast-hygiene rule)
	function notify(text, isError) {
		var el = document.querySelector('[data-cck-banner]');
		if (!el) {
			el = document.createElement('div');
			el.dataset.cckBanner = '1';
			el.className = 'cck-banner';
			el.setAttribute('role', isError ? 'alert' : 'status');
			document.querySelector('[data-cck-app]').prepend(el);
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


	// ---------------- DS-4 directory picker ----------------
	// Typed refs out — searchable pickers + removable chips only.
	// Single mode: hidden input carries the ref, chip shows the label.
	// Multi mode (access lists): hidden input carries a JSON array.
	function buildRefPick(box) {
		var name = box.dataset.cckRefpick;
		var kind = box.dataset.cckRefpickKind || 'staff';
		var multi = box.classList.contains('cck-refpick--multi');
		// Search input FIRST in DOM order — the shared field-error renderer
		// queries [name=…]/[data-field=…] and must paint the visible control,
		// never the hidden carrier. data-field maps server error keys here.
		box.innerHTML =
			'<input type="search" class="cck-refpick__input" data-field="' + name + '" autocomplete="off" ' +
				'placeholder="' + esc(tr('Search staff…')) + '" aria-label="' + esc(tr('Search staff')) + '" ' +
				'aria-expanded="false" role="combobox" aria-autocomplete="list">' +
			'<input type="hidden" name="' + name + '">' +
			'<ul class="cck-refpick__list" role="listbox" aria-label="' + esc(tr('Search staff')) + '" hidden></ul>' +
			'<div class="cck-refpick__chips"></div>';
		var input = box.querySelector('.cck-refpick__input');
		var list = box.querySelector('.cck-refpick__list');
		var chips = box.querySelector('.cck-refpick__chips');
		var hidden = box.querySelector('input[type=hidden]');
		var inflight = null; // AbortController — stale-response guard (DS-4)
		var seq = 0; // per-picker — a shared counter drops siblings' results
		var selected = [];

		function syncHidden() {
			hidden.value = multi ? JSON.stringify(selected.map(function (x) { return x.ref; })) : (selected[0] ? selected[0].ref : '');
		}
		function renderChips() {
			chips.innerHTML = '';
			selected.forEach(function (item, i) {
				var chip = document.createElement('span');
				chip.className = 'cck-refpick__chip';
				chip.textContent = item.label + ' ';
				var rm = document.createElement('button');
				rm.type = 'button';
				rm.className = 'cck-refpick__rm';
				rm.textContent = '×';
				rm.setAttribute('aria-label', tr('Remove') + ' ' + item.label);
				rm.onclick = function () { selected.splice(i, 1); syncHidden(); renderChips(); box.dispatchEvent(new Event('cck-refpick-change')); };
				chip.appendChild(rm);
				chips.appendChild(chip);
			});
			input.hidden = !multi && selected.length > 0;
		}
		function pick(item) {
			if (!multi) { selected = []; }
			if (!selected.some(function (x) { return x.ref === item.ref; })) { selected.push(item); }
			syncHidden(); renderChips();
			input.value = ''; list.hidden = true; input.setAttribute('aria-expanded', 'false');
			box.dispatchEvent(new Event('cck-refpick-change'));
		}
		function search(q) {
			if (inflight) { inflight.abort(); }
			var mySeq = ++seq;
			inflight = new AbortController();
			var url = '/api/directory?q=' + encodeURIComponent(q) + (kind === 'group' ? '&type=group' : '');
			fetch(OC.generateUrl('/apps/carecheck' + url), { headers: { 'requesttoken': OC.requestToken }, signal: inflight.signal })
				.then(function (r) { return r.json(); })
				.then(function (r) {
					if (mySeq !== seq || !r.success) { return; }
					var results = r.data.results || [];
					list.innerHTML = '';
					results.forEach(function (item) {
						var li = document.createElement('li');
						li.setAttribute('role', 'option');
						li.className = 'cck-refpick__opt';
						li.tabIndex = 0;
						li.textContent = item.label + (item.kind === 'group' ? ' (' + tr('group') + ')' : '');
						li.onclick = function () { pick(item); };
						li.onkeydown = function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); pick(item); } };
						list.appendChild(li);
					});
					list.hidden = results.length === 0;
					input.setAttribute('aria-expanded', results.length ? 'true' : 'false');
				})
				.catch(function () { /* aborted or offline — keep the list as-is */ });
		}
		var deb = null;
		input.addEventListener('input', function () {
			clearTimeout(deb);
			var q = input.value.trim();
			deb = setTimeout(function () { search(q); }, 250);
		});
		input.addEventListener('focus', function () { search(input.value.trim()); });
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { list.hidden = true; input.setAttribute('aria-expanded', 'false'); }
		});
		document.addEventListener('click', function (ev) {
			if (!box.contains(ev.target)) { list.hidden = true; input.setAttribute('aria-expanded', 'false'); }
		});
		box._refpick = {
			get value() { return hidden.value; },
			set: function (ref, label) { selected = ref ? [{ ref: ref, label: label || ref }] : []; syncHidden(); renderChips(); },
			setList: function (refs, labels) {
				selected = (refs || []).map(function (r2, i) { return { ref: r2, label: (labels && labels[i]) || r2 }; });
				syncHidden(); renderChips();
			},
			clear: function () { selected = []; syncHidden(); renderChips(); },
		};
		renderChips();
	}
	document.querySelectorAll('[data-cck-refpick]').forEach(buildRefPick);

	// ---------------- NH-11 teams ----------------
	var teamsCache = [];
	function loadTeams() {
		var box = document.querySelector('[data-cck-team-list]');
		return api('/api/teams').then(function (r) {
			if (!r.success) {
				teamsCache = [];
				if (box) { box.innerHTML = '<p class="cck-empty">' + esc(r.message || tr('No access.')) + '</p>'; }
				return;
			}
			teamsCache = r.data.teams || [];
			renderTeams();
		});
	}
	function teamOptions(selVal) {
		return '<option value="">' + esc(tr('No team')) + '</option>' + teamsCache.map(function (t) {
			return '<option value="' + t.id + '"' + (String(selVal || '') === String(t.id) ? ' selected' : '') + '>' + esc(t.name) + '</option>';
		}).join('');
	}
	function renderTeams() {
		var box = document.querySelector('[data-cck-team-list]');
		if (!box) { return; }
		box.innerHTML = teamsCache.map(function (t) {
			var leads = (t.leads || []).map(function (u, i) {
				return '<span class="cck-refpick__chip">' + esc((t.leadLabels || [])[i] || u) +
					' <button type="button" class="cck-refpick__rm" data-cck-team-rm="' + t.id + ':' + esc(u) + '" aria-label="' + esc(tr('Remove lead')) + '">×</button></span>';
			}).join('') || '<span class="cck-muted">' + esc(tr('No leads yet.')) + '</span>';
			return '<div class="cck-team" data-cck-team="' + t.id + '"><h3>' + esc(t.name) + '</h3>' +
				'<p class="cck-muted">' + esc(tr('Clients')) + ': ' + t.clients + ' · ' + esc(tr('Members')) + ': ' + t.members + '</p>' +
				'<div class="cck-team__leads">' + leads + '</div>' +
				'<div class="cck-refpick" data-cck-refpick="teamlead_' + t.id + '" data-cck-refpick-kind="uid"></div></div>';
		}).join('') || '<p class="cck-empty">' + esc(tr('No teams yet — create the first one above.')) + '</p>';
		box.querySelectorAll('[data-cck-refpick]').forEach(buildRefPick);
		box.querySelectorAll('[data-cck-refpick]').forEach(function (pick) {
			pick.addEventListener('cck-refpick-change', function () {
				var teamId = parseInt(pick.dataset.cckRefpick.split('_')[1], 10);
				var uid = pick._refpick.value;
				if (!uid || !uid.startsWith('uid:')) { return; }
				api('/api/teams/' + teamId + '/lead', { method: 'PUT', body: JSON.stringify({ uid: uid.slice(4) }) })
					.then(function (r) {
						if (r.success) { notify(tr('Lead assigned.')); loadTeams(); }
						else { notify(r.message || tr('Failed.'), true); }
					});
			});
		});
	}
	document.addEventListener('click', function (e) {
		var rm = e.target.closest('[data-cck-team-rm]');
		if (!rm) { return; }
		var parts = rm.dataset.cckTeamRm.split(':');
		api('/api/teams/' + parts[0] + '/lead/' + encodeURIComponent(parts[1]), { method: 'DELETE' })
			.then(function (r) {
				if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
				loadTeams();
			});
	});
	document.addEventListener('submit', function (e) {
		if (!e.target.matches('[data-cck-team-form]')) { return; }
		e.preventDefault();
		var f = new FormData(e.target);
		withBusy(submitBtn(e.target), function () {
			return api('/api/teams', { method: 'POST', body: JSON.stringify({ name: f.get('name') }) })
				.then(function (r) {
					if (r.success) { e.target.reset(); clearFormErrors(e.target); loadTeams(); }
					else { reportForm(e.target, r); }
				});
		});
	});
	// team selects on client detail + member rows
	function bindTeamSelect(sel, kind, id) {
		sel.addEventListener('change', function () {
			api('/api/' + kind + '/' + id + '/team', { method: 'PUT', body: JSON.stringify({ team_id: sel.value === '' ? null : parseInt(sel.value, 10) }) })
				.then(function (r) { notify(r.success ? tr('Saved.') : (r.message || tr('Failed.')), !r.success); });
		});
	}

	// ---------------- routing ----------------
	var currentClient = null;

	var SETTINGS_SECTIONS = ['general', 'companion', 'access', 'support'];
	var mountedSettings = {};
	// null = unknown (first /api/settings round-trip pending). The access
	// section stays unmounted until the server confirms admin — DS-2 door.
	var settingsAdmin = null;

	// §13 settings IA: one hash URL per topic; the section body mounts from
	// its <template> once, then loadSettings() repopulates on every visit.
	function mountSettingsSection(sec) {
		var slot = document.querySelector('[data-cck-settings-section="' + sec + '"]');
		var tpl = document.querySelector('template[data-cck-settings-tpl="' + sec + '"]');
		if (!slot || !tpl) { return; }
		if (!slot.firstElementChild) {
			slot.appendChild(tpl.content.cloneNode(true));
			slot.querySelectorAll('[data-cck-refpick]').forEach(buildRefPick);
			// gate until the first hydrate lands — see gateSettingsForms
			if (!settingsReady) { slot.querySelectorAll('[data-cck-settings-form] [type="submit"]').forEach(function (b) { b.disabled = true; }); }
			mountedSettings[sec] = true;
		}
	}

	function route(skipSettingsLoad) {
		var h = location.hash.replace(/^#\/?/, '') || 'board';
		var parts = h.split('/');
		var view = parts[0];
		var sub = parts[1] || '';
		var id = parseInt(sub, 10);
		if (view === 'settings') {
			var sec = SETTINGS_SECTIONS.indexOf(sub) >= 0 ? sub : 'general';
			if (sec === 'access' && settingsAdmin !== true) {
				if (settingsAdmin === false) { location.replace('#/settings/general'); return; }
				sec = 'general';
			}
			view = 'settings-' + sec;
			mountSettingsSection(sec);
		}
		document.querySelectorAll('.cck-view').forEach(function (v) { v.hidden = v.dataset.cckView !== view; });
		document.querySelectorAll('.cck-nav-link').forEach(function (a) {
			var match = a.dataset.cckNav === view
				|| (a.dataset.cckNav === 'settings' && view.indexOf('settings-') === 0);
			a.classList.toggle('active', match);
			if (match) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		document.querySelectorAll('[data-cck-subnav]').forEach(function (a) {
			var match = parts[0] === 'settings' && a.dataset.cckSubnav === sub;
			a.classList.toggle('active', match);
			if (match) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		if (view === 'client' && id > 0) { loadClientDetail(id); return; }
		var loaders = { board: loadBoard, clients: loadClients, members: loadMembers, inbox: loadInbox, catalog: loadCatalog, tours: loadTours };
		if (view.indexOf('settings-') === 0 && !skipSettingsLoad) { loadSettings(); }
		(loaders[view] || function () {})();
	}

	// ---------------- coverage board ----------------
	var templateNames = null;
	var boardDate = today();
	function isoShift(days) {
		var p = boardDate.split('-');
		var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
		d.setDate(d.getDate() + days);
		return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
	}
	function tourLabel(t) {
		var name = (templateNames && t.templateId && templateNames[t.templateId]) || null;
		var exec = t.executorLabel || t.executorRef || tr('Unassigned');
		return name ? name + ' — ' + exec : exec;
	}
	var STOP_STATUS = {
		pending: ['cck-badge--info', 'planned'],
		in_progress: ['cck-badge--warning', 'in progress'],
		done: ['cck-badge--success', 'done'],
		skipped: ['cck-badge--warning', 'skipped'],
		uncovered: ['cck-badge--danger', 'uncovered'],
	};
	function stopBadge(status) {
		var s = STOP_STATUS[status] || ['cck-badge--info', status || 'planned'];
		return '<span class="cck-badge ' + s[0] + '"><span class="cck-badge__dot" aria-hidden="true"></span>' + esc(tr(s[1])) + '</span>';
	}
	function stopUncovered(s) { return s.status === 'uncovered' || !s.executorRef; }
	function loadBoard() {
		var dateInput = document.querySelector('[data-cck-board-date]');
		if (dateInput && dateInput.value !== boardDate) { dateInput.value = boardDate; }
		var namesReady = templateNames
			? Promise.resolve()
			: api('/api/tour-templates').then(function (r) {
				templateNames = {};
				if (r.success) { ((r.data && r.data.templates) || []).forEach(function (t) { templateNames[t.id] = t.name; }); }
			});
		Promise.all([namesReady, api('/api/coverage/' + boardDate)]).then(function (res) {
			var r = res[1];
			var el = document.querySelector('[data-cck-board]');
			var uncoveredEl = document.querySelector('[data-cck-uncovered]');
			var uncoveredList = document.querySelector('[data-cck-uncovered-list]');
			var statsEl = document.querySelector('[data-cck-stats]');
			if (!r.success) { el.innerHTML = '<p class="cck-empty">' + esc(r.message || tr('No access.')) + '</p>'; if (statsEl) { statsEl.hidden = true; } return; }
			var tours = r.data.tours || [];
			var cronBanner = document.querySelector('[data-cck-cron-banner]');
			if (cronBanner) { cronBanner.hidden = !r.data.cronStale; }

			// setup checklist: show until all four steps exist
			var setup = document.querySelector('[data-cck-setup]');
			if (setup) {
				setup.hidden = tours.length > 0;
			}

			// uncovered queue (spec: never invisible)
			var uncovered = [];
			var counts = { total: 0, uncovered: 0, active: 0, done: 0 };
			tours.forEach(function (t) {
				t.stops.forEach(function (s) {
					counts.total++;
					var isUncovered = stopUncovered(s);
					if (isUncovered) { uncovered.push(s); counts.uncovered++; }
					else if (s.status === 'done' || s.status === 'skipped') { counts.done++; }
					else { counts.active++; }
				});
			});
			if (statsEl) {
				statsEl.hidden = counts.total === 0 && uncovered.length === 0;
				['total', 'uncovered', 'active', 'done'].forEach(function (k) {
					var n = statsEl.querySelector('[data-cck-stat="' + k + '"]');
					if (n) { n.textContent = String(counts[k]); }
				});
			}
			uncoveredEl.hidden = uncovered.length === 0;
			uncoveredList.innerHTML = uncovered.map(function (s) {
				var who = s.clientId
					? '<a class="cck-link" href="#/client/' + s.clientId + '">' + esc(s.clientName || ('#' + s.clientId)) + '</a>'
					: '<span>' + esc(s.locText || '') + '</span>';
				return '<li class="cck-stop cck-stop--uncovered"><span class="cck-stop-time">' + fmtMin(s.winStart) + '</span>' +
					who +
					'<button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-assign="' + s.id + '">' + esc(tr('Assign…')) + '</button></li>';
			}).join('');

			el.innerHTML = tours.length === 0 ? '<div class="cck-empty-state cck-empty"><p class="cck-empty-state__title">' + esc(tr('No tours on this day.')) + '</p>' +
				'<p class="cck-empty-state__text">' + esc(tr('Pick another day above, or generate tours from published templates.')) + '</p></div>'
				: tours.map(function (t) {
				var tDone = t.stops.filter(function (s) { return s.status === 'done' || s.status === 'skipped'; }).length;
				return '<section class="cck-tour"><header class="cck-tour__head"><h2>' + esc(tourLabel(t)) + '</h2>' +
					'<span class="cck-tour__progress">' + tDone + '/' + t.stops.length + ' ' + esc(tr('done')) + '</span></header><ul>' +
					t.stops.map(function (s) {
						var badge = s.timeKind === 'deadline' ? tr('by') + ' ' + fmtMin(s.winEnd)
							: s.timeKind === 'fixed' ? tr('at') + ' ' + fmtMin(s.winStart)
							: fmtMin(s.winStart) + '–' + fmtMin(s.winEnd);
						var who = s.clientId
							? '<a class="cck-stop__who cck-link" href="#/client/' + s.clientId + '">' + esc(s.clientName || ('#' + s.clientId)) + '</a>'
							: '<span class="cck-stop__who">' + esc(s.locText || '') + '</span>';
						return '<li class="cck-stop cck-stop--' + esc(s.status) + '">' +
							'<span class="cck-stop-time">' + esc(badge) + '</span>' +
							who +
							(s.isDemo ? ' <em class="cck-demo">' + esc(tr('Practice')) + '</em>' : '') +
							stopBadge(s.status) +
							((s.status === 'uncovered' || !s.executorRef) ? ' <button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-assign="' + s.id + '">' + esc(tr('Assign…')) + '</button>' : '') +
							'</li>';
					}).join('') + '</ul></section>';
			}).join('');

			document.querySelectorAll('[data-cck-assign]').forEach(function (b) {
				b.onclick = function () { openAssignDialog(b.dataset.cckAssign); };
			});
		});
	}
	// date navigation — prev/next/today + direct pick; shared pattern, board only
	(function () {
		var input = document.querySelector('[data-cck-board-date]');
		if (!input) { return; }
		input.value = boardDate;
		document.querySelector('[data-cck-prev-day]').addEventListener('click', function () { boardDate = isoShift(-1); loadBoard(); });
		document.querySelector('[data-cck-next-day]').addEventListener('click', function () { boardDate = isoShift(1); loadBoard(); });
		document.querySelector('[data-cck-today-btn]').addEventListener('click', function () { boardDate = today(); loadBoard(); });
		input.addEventListener('change', function () {
			if (/^\d{4}-\d{2}-\d{2}$/.test(input.value)) { boardDate = input.value; loadBoard(); }
		});
	})();

	// ---------------- assign dialog (DS-4: picker, never raw ref typing) --
	var assignStopId = null;
	function openAssignDialog(stopId) {
		assignStopId = stopId;
		var dlg = document.querySelector('[data-cck-assign-dialog]');
		var pick = dlg.querySelector('[data-cck-refpick="assign_ref"]');
		pick._refpick.clear();
		var btn = dlg.querySelector('[data-cck-assign-confirm]');
		btn.disabled = true;
		dlg._invoker = document.activeElement;
		dlg.showModal();
		var search = pick.querySelector('.cck-refpick__input');
		if (search) { search.focus(); }
	}
	// ---------------- resolve dialog (replaces window.prompt — audit note is
	// collected in a real form, focused, cancellable, and validated) -------
	var resolveVisitId = null;
	var resolveDlg = document.querySelector('[data-cck-resolve-dialog]');
	function openResolveDialog(visitId) {
		resolveVisitId = visitId;
		var note = resolveDlg.querySelector('[data-cck-resolve-note]');
		note.value = '';
		note.removeAttribute('aria-invalid');
		resolveDlg._invoker = document.activeElement;
		resolveDlg.showModal();
		note.focus();
	}
	if (resolveDlg) {
		var resolveNote = resolveDlg.querySelector('[data-cck-resolve-note]');
		resolveDlg.querySelector('[data-cck-resolve-cancel]').onclick = function () { resolveDlg.close(); };
		resolveDlg.querySelector('[data-cck-resolve-confirm]').onclick = function () {
			var note = resolveNote.value.trim();
			if (!note) {
				resolveNote.setAttribute('aria-invalid', 'true');
				resolveNote.focus();
				return;
			}
			if (resolveVisitId === null) { resolveDlg.close(); return; }
			resolveDlg.close();
			api('/api/visits/' + resolveVisitId + '/resolve', { method: 'POST', body: JSON.stringify({ resolution: note }) })
				.then(function (rr) {
					notify(rr.success ? tr('Resolved.') : (rr.message || tr('Invalid value.')), !rr.success);
					loadReviews();
				});
		};
		resolveNote.addEventListener('input', function () { resolveNote.removeAttribute('aria-invalid'); });
	}

	// dialogs close on Escape at the dialog level — never trap focus
	// or swallow the key inside a child control. WCAG 2.4.3: close
	// returns focus to the invoking control.
	document.querySelectorAll('dialog.cck-dialog').forEach(function (dlg) {
		dlg.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { ev.preventDefault(); dlg.close(); }
		});
		dlg.addEventListener('close', function () {
			var back = dlg._invoker;
			dlg._invoker = null;
			if (back && document.contains(back) && typeof back.focus === 'function') { back.focus(); }
		});
	});

	var assignDlg = document.querySelector('[data-cck-assign-dialog]');
	if (assignDlg) {
		assignDlg.querySelector('[data-cck-refpick="assign_ref"]').addEventListener('cck-refpick-change', function () {
			assignDlg.querySelector('[data-cck-assign-confirm]').disabled =
				!assignDlg.querySelector('[data-cck-refpick="assign_ref"]')._refpick.value;
		});
		assignDlg.querySelector('[data-cck-assign-cancel]').onclick = function () { assignDlg.close(); };
		assignDlg.querySelector('[data-cck-assign-confirm]').onclick = function () {
			var ref = assignDlg.querySelector('[data-cck-refpick="assign_ref"]')._refpick.value;
			if (!ref || assignStopId === null) { return; }
			assignDlg.close();
			api('/api/stops/' + assignStopId + '/assign', { method: 'POST', body: JSON.stringify({ executor_ref: ref }) })
				.then(function (rr) {
					if (!rr.success) { notify(rr.message || tr('Invalid value.'), true); return; }
					notify(tr('Assigned.'));
					loadBoard();
				});
		};
	}

	document.querySelector('[data-cck-generate]').addEventListener('click', function (e) {
		withBusy(e.currentTarget, function () {
			return api('/api/tours/generate', { method: 'POST', body: '{}' }).then(function (r) {
				notify(r.success ? tr('Tours generated.') : (r.message || tr('Invalid value.')), !r.success);
				loadBoard();
			});
		});
	});

	// ---------------- clients ----------------
	var QUAL_LABEL = { hilfskraft: 'Care assistant', betreuer: 'Caregiver', fachkraft: 'Qualified caregiver' };
	function qualLabel(q) { return QUAL_LABEL[q] ? tr(QUAL_LABEL[q]) : (q ? esc(q) : tr('Any')); }
	var clientsCache = [];
	var clientsFilter = { q: '', qual: '' };
	function renderClients() {
		var tb = document.querySelector('[data-cck-clients] tbody');
		var count = document.querySelector('[data-cck-clients-count]');
		var q = (clientsFilter.q || '').toLowerCase();
		var rows = clientsCache.filter(function (c) {
			if (clientsFilter.qual && c.requiresQual !== clientsFilter.qual) { return false; }
			if (q && (c.name || '').toLowerCase().indexOf(q) < 0 && (c.street || '').toLowerCase().indexOf(q) < 0) { return false; }
			return true;
		});
		if (count) {
			count.textContent = rows.length === clientsCache.length
				? rows.length + ' ' + tr('clients')
				: rows.length + ' / ' + clientsCache.length + ' ' + tr('clients');
		}
		tb.innerHTML = rows.map(function (c) {
			return '<tr><td data-cell="' + esc(tr('Name')) + '"><a href="#/client/' + c.id + '">' + esc(c.name) + '</a></td>' +
				'<td data-cell="' + esc(tr('Street')) + '">' + esc(c.street) + '</td>' +
				'<td data-cell="' + esc(tr('Qualification')) + '">' + qualLabel(c.requiresQual) + '</td>' +
				'<td><button type="button" class="cck-btn cck-btn--danger cck-btn--sm" data-cck-archive="' + c.id + '">' + esc(tr('Archive')) + '</button></td></tr>';
		}).join('') || '<tr><td colspan="4" class="cck-empty">' + esc(clientsCache.length ? tr('No clients match the filter.') : tr('No clients yet — add the first one below.')) + '</td></tr>';
		tb.querySelectorAll('[data-cck-archive]').forEach(function (b) {
			b.onclick = function () {
				confirmAsk(tr('Archive this client? Active tours and records stay readable, but no new stops are planned.'), tr('Archive'))
					.then(function (yes) {
						if (!yes) { return; }
						api('/api/clients/' + b.dataset.cckArchive, { method: 'DELETE' }).then(function (r) {
							if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
							loadClients();
						});
					});
			};
		});
	}
	function loadClients() {
		api('/api/clients').then(function (r) {
			var tb = document.querySelector('[data-cck-clients] tbody');
			if (!r.success) { tb.innerHTML = '<tr><td colspan="4" class="cck-empty">' + esc(r.message || tr('No access.')) + '</td></tr>'; return; }
			clientsCache = (r.data && r.data.clients) || [];
			renderClients();
		});
	}
	if (window.CheckViewComponents) {
		CheckViewComponents.bindListFilter(document.querySelector('[data-cck-clients-filter]'), function (v) {
			clientsFilter = { q: v.q || '', qual: v.qual || '' };
			renderClients();
		});
	}
	document.querySelector('[data-cck-client-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = new FormData(e.target);
		withBusy(submitBtn(e.target), function () {
			return api('/api/clients', { method: 'POST', body: JSON.stringify({ name: f.get('name'), street: f.get('street'), requires_qual: f.get('requires_qual') || null }) })
				.then(function (r) {
					if (r.success) { e.target.reset(); clearFormErrors(e.target); loadClients(); }
					else { reportForm(e.target, r); }
				});
		});
	});

	// ---------------- client detail ----------------
	function loadClientDetail(id) {
		currentClient = id;
		// stale-data guard: while the fetch runs the previous client's record
		// must not sit under the new URL.
		document.querySelector('[data-cck-client-name]').textContent = tr('Loading…');
		api('/api/clients/' + id).then(function (r) {
			if (!r.success) { notify(r.message || tr('Not found.'), true); location.hash = '#/clients'; return; }
			var c = r.data.client;
			document.querySelector('[data-cck-client-name]').textContent = c.payload.name || '';
			var f = document.querySelector('[data-cck-client-edit]');
			f.elements.base_revision.value = c.revision;
			f.elements.name.value = c.payload.name || '';
			f.elements.street.value = c.payload.street || '';
			f.elements.accessNote.value = c.payload.accessNote || '';
			f.elements.risks.value = (c.payload.risks || []).join(', ');
			f.elements.photo_consent.checked = !!c.payload.photo_consent;
			var ts = f.elements.team_id;
			if (ts) {
				loadTeams().then(function () {
					ts.innerHTML = teamOptions(c.teamId);
					if (!ts.dataset.cckBound) { ts.dataset.cckBound = '1'; bindTeamSelect(ts, 'clients', id); }
				});
			}
			loadPhotos(id, !!c.payload.photo_consent);
			var pp = document.querySelector('[data-cck-refpick="primary_ref"]');
			var sp = document.querySelector('[data-cck-refpick="sub_ref"]');
			if (pp) { pp._refpick.set(c.primaryRef || '', c.primaryRefLabel || c.primaryRef || ''); }
			if (sp) { sp._refpick.set(c.subRef || '', c.subRefLabel || c.subRef || ''); }
			loadPlanTasks(c);
			loadJournal(id);
		});
	}
	document.querySelector('[data-cck-client-back]').addEventListener('click', function () { location.hash = '#/clients'; });
	// in-page section chips — long detail page gets a mini-IA instead of a wall
	document.querySelectorAll('[data-cck-section]').forEach(function (chip) {
		chip.addEventListener('click', function () {
			var target = document.getElementById('cck-sec-' + chip.dataset.cckSection);
			if (!target) { return; }
			target.scrollIntoView({ block: 'start' });
			target.focus({ preventScroll: true });
			document.querySelectorAll('[data-cck-section]').forEach(function (c) {
				c.classList.toggle('active', c === chip);
				if (c === chip) { c.setAttribute('aria-current', 'true'); } else { c.removeAttribute('aria-current'); }
			});
		});
	});
	document.querySelector('[data-cck-client-edit]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = e.target;
		withBusy(submitBtn(f), function () {
		return api('/api/clients/' + currentClient, { method: 'PUT', body: JSON.stringify({
			base_revision: parseInt(f.elements.base_revision.value, 10),
			name: f.elements.name.value,
			street: f.elements.street.value,
			accessNote: f.elements.accessNote.value,
			risks: f.elements.risks.value.split(',').map(function (x) { return x.trim(); }).filter(Boolean),
			primary_ref: document.querySelector('[data-cck-refpick="primary_ref"]')._refpick.value || null,
			sub_ref: document.querySelector('[data-cck-refpick="sub_ref"]')._refpick.value || null,
			photo_consent: f.elements.photo_consent.checked,
		}) }).then(function (r) {
			if (r.success) { clearFormErrors(f); notify(tr('Saved.')); loadClientDetail(currentClient); }
			else { reportForm(f, r); }
		});
		});
	});

	// plan editor — pick from catalog; precheck the published selection.
	// Sorted card grid + a filter so long catalogs stay scannable. Filter
	// only toggles `hidden` — rows stay in the DOM so a checked task outside
	// the current filter still publishes (no silent drops, no lost checks).
	function loadPlanTasks(client) {
		var form = document.querySelector('[data-cck-plan-form]');
		form.elements.base_revision.value = client.plan ? client.plan.revision : 0;
		var current = {};
		((client.plan && client.plan.tasks) || []).forEach(function (t) { current[t.task_code || t.code] = true; });
		var box = document.querySelector('[data-cck-plan-tasks]');
		var filter = document.querySelector('[data-cck-plan-filter]');
		if (filter) { filter.value = ''; } // stale filter must not hide the new client's picks
		api('/api/catalog').then(function (r) {
			var tasks = ((r.data && r.data.tasks) || []).slice();
			tasks.sort(function (a, b) {
				return String(a.label || '').localeCompare(String(b.label || ''), undefined, { sensitivity: 'base' });
			});
			box.innerHTML = tasks.map(function (t) {
				return '<label class="cck-plan-task"><input type="checkbox" name="task" value="' + esc(t.code) + '"' +
					' data-label="' + esc(t.label) + '"' + (current[t.code] ? ' checked' : '') + '> ' +
					'<span class="cck-plan-task__text">' + esc(t.icon ? t.icon + ' ' : '') + esc(t.label) + '</span></label>';
			}).join('') || '<p class="cck-empty">' + esc(tr('Add catalog tasks first.')) + '</p>';
			var wrap = document.querySelector('[data-cck-plan-filter-wrap]');
			if (wrap) { wrap.hidden = tasks.length < 8; }
		});
	}
	(function () {
		var filter = document.querySelector('[data-cck-plan-filter]');
		if (!filter) { return; }
		filter.addEventListener('input', function () {
			var q = filter.value.trim().toLowerCase();
			document.querySelectorAll('#cck-sec-plan .cck-plan-task').forEach(function (l) {
				var cb = l.querySelector('input[name=task]');
				var hay = (((cb && cb.dataset.label) || '') + ' ' + ((cb && cb.value) || '')).toLowerCase();
				l.hidden = q !== '' && hay.indexOf(q) < 0;
			});
		});
	})();
	document.querySelector('[data-cck-plan-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = e.target;
		var tasks = Array.prototype.slice.call(f.querySelectorAll('input[name=task]:checked')).map(function (el) {
			return { task_code: el.value, label: el.dataset.label || el.value };
		});
		withBusy(submitBtn(f), function () {
			return api('/api/clients/' + currentClient + '/plan/publish', { method: 'POST', body: JSON.stringify({ tasks: tasks, base_revision: parseInt(f.elements.base_revision.value, 10) }) })
				.then(function (r) {
					if (r.success) { notify(tr('Plan published.')); loadClientDetail(currentClient); }
					else { reportForm(f, r); }
				});
		});
	});

	function loadJournal(clientId) {
		api('/api/clients/' + clientId + '/journal').then(function (r) {
			var el = document.querySelector('[data-cck-journal]');
			if (!r.success) { el.innerHTML = '<li class="cck-empty">' + esc(r.message || tr('No access.')) + '</li>'; return; }
			el.innerHTML = ((r.data && r.data.entries) || []).map(function (en) {
				return '<li><strong>' + esc(tokenLabel(JOURNAL_TYPE, en.type)) + '</strong> — ' + esc(en.actorLabel || en.actorRef) + ' <em>' + esc(fmtTs(en.ts)) + '</em>' +
					(en.text ? ' — ' + esc(en.text) : '') + '</li>';
			}).join('') || '<li class="cck-empty">' + esc(tr('No entries yet.')) + '</li>';
		});
	}
	document.querySelector('[data-cck-journal-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = new FormData(e.target);
		withBusy(submitBtn(e.target), function () {
			return api('/api/clients/' + currentClient + '/journal', { method: 'POST', body: JSON.stringify({ text: f.get('text') }) })
				.then(function (r) {
					if (r.success) { e.target.reset(); loadJournal(currentClient); }
					else { reportForm(e.target, r); }
				});
		});
	});

	// exports
	document.querySelector('[data-cck-export-csv]').addEventListener('click', function () {
		var p = document.querySelector('[data-cck-export-period]').value || today().slice(0, 7);
		window.location = OC.generateUrl('/apps/carecheck/api/clients/' + currentClient + '/export/csv?period=' + p);
	});
	document.querySelector('[data-cck-export-pdf]').addEventListener('click', function () {
		var p = document.querySelector('[data-cck-export-period]').value || today().slice(0, 7);
		window.location = OC.generateUrl('/apps/carecheck/api/clients/' + currentClient + '/export/pdf?period=' + p);
	});
	// NH-07: generic structured billing interchange (never a claims format)
	document.querySelector('[data-cck-export-json]').addEventListener('click', function () {
		var p = document.querySelector('[data-cck-export-period]').value || today().slice(0, 7);
		window.location = OC.generateUrl('/apps/carecheck/api/clients/' + currentClient + '/export/billing?period=' + p + '&fmt=json');
	});
	document.querySelector('[data-cck-export-xml]').addEventListener('click', function () {
		var p = document.querySelector('[data-cck-export-period]').value || today().slice(0, 7);
		window.location = OC.generateUrl('/apps/carecheck/api/clients/' + currentClient + '/export/billing?period=' + p + '&fmt=xml');
	});

	// ---------------- NH-04 photos (consent-gated) ----------------
	function loadPhotos(clientId, consent) {
		var hint = document.querySelector('[data-cck-photo-hint]');
		var controls = document.querySelector('[data-cck-photo-controls]');
		var grid = document.querySelector('[data-cck-photos]');
		hint.hidden = consent;
		controls.hidden = !consent;
		if (!consent) { grid.innerHTML = ''; return; }
		api('/api/clients/' + clientId + '/photos').then(function (r) {
			var photos = (r.data && r.data.photos) || [];
			grid.innerHTML = photos.map(function (p) {
				return '<figure class="cck-photo"><a href="' + esc(OC.generateUrl('/apps/carecheck/api/clients/' + clientId + '/photos/' + p.id)) +
					'" target="_blank" rel="noopener" aria-label="' + esc(tr('Open photo')) + '"><img loading="lazy" alt="' + esc(tr('Client photo')) + ' ' + esc(String(p.id)) +
					'" src="' + esc(OC.generateUrl('/apps/carecheck/api/clients/' + clientId + '/photos/' + p.id)) + '"></a>' +
					'<figcaption>' + esc(fmtTs(p.createdAt)) + ' · ' + esc(p.byLabel || p.by || '') +
					' <button type="button" class="cck-btn cck-btn--ghost cck-btn--sm" data-cck-photo-del="' + p.id + '">' + esc(tr('Delete')) + '</button></figcaption></figure>';
			}).join('') || '<p class="cck-empty">' + esc(tr('No photos yet.')) + '</p>';
		});
	}
	document.querySelector('[data-cck-photo-file]').addEventListener('change', function (e) {
		var file = e.target.files && e.target.files[0];
		if (!file) { return; }
		if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { notify(tr('Only JPEG, PNG or WebP photos.'), true); e.target.value = ''; return; }
		var reader = new FileReader();
		reader.onload = function () {
			var b64 = String(reader.result).split(',')[1] || '';
			api('/api/clients/' + currentClient + '/photos', { method: 'POST', body: JSON.stringify({ mime: file.type, data_b64: b64 }) })
				.then(function (r) {
					if (r.success) { notify(tr('Photo stored.')); loadPhotos(currentClient, true); }
					else { notify(r.message || tr('Upload failed.'), true); }
				});
			e.target.value = '';
		};
		reader.readAsDataURL(file);
	});
	document.querySelector('[data-cck-photos]').addEventListener('click', function (e) {
		var btn = e.target.closest('[data-cck-photo-del]');
		if (!btn) { return; }
		confirmAsk(tr('Delete this photo permanently? It is removed from the encrypted store.'), tr('Delete'))
			.then(function (yes) {
				if (!yes) { return; }
				api('/api/clients/' + currentClient + '/photos/' + btn.dataset.cckPhotoDel, { method: 'DELETE' })
					.then(function (r) {
						if (r.success) { notify(tr('Photo deleted.')); loadPhotos(currentClient, true); }
						else { notify(r.message || tr('Delete failed.'), true); }
					});
			});
	});

	// ---------------- tours ----------------
	var templatesCache = [];
	var tourStatusFilter = 'all';
	function weekdayChips(set) {
		var abbr = [tr('Mon'), tr('Tue'), tr('Wed'), tr('Thu'), tr('Fri'), tr('Sat'), tr('Sun')];
		var days = String(set || '').split(',').map(function (x) { return parseInt(x.trim(), 10); })
			.filter(function (d) { return d >= 1 && d <= 7; });
		if (!days.length) { return esc(String(set || '—')); }
		return days.map(function (d) {
			return '<span class="cck-chip">' + esc(abbr[d - 1]) + '</span>';
		}).join(' ');
	}
	function renderTours() {
		var tb = document.querySelector('[data-cck-templates] tbody');
		var count = document.querySelector('[data-cck-tours-count]');
		var rows = templatesCache.filter(function (t) {
			return tourStatusFilter === 'all' || t.status === tourStatusFilter;
		});
		if (count) {
			count.textContent = rows.length === templatesCache.length
				? rows.length + ' ' + tr('templates')
				: rows.length + ' / ' + templatesCache.length + ' ' + tr('templates');
		}
		tb.innerHTML = rows.map(function (t) {
			var statusBadge = t.status === 'published'
				? '<span class="cck-badge cck-badge--success"><span class="cck-badge__dot" aria-hidden="true"></span>' + esc(tr('Published')) + '</span>'
				: '<span class="cck-badge cck-badge--info"><span class="cck-badge__dot" aria-hidden="true"></span>' + esc(tr('Draft')) + '</span>';
			return '<tr><td data-cell="' + esc(tr('Name')) + '"><strong>' + esc(t.name) + '</strong></td>' +
				'<td data-cell="' + esc(tr('Weekdays')) + '">' + weekdayChips(t.weekdaySet) + '</td>' +
				'<td data-cell="' + esc(tr('Status')) + '">' + statusBadge + '</td>' +
				'<td><button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-tpl-edit="' + t.id + '">' + esc(tr('Edit')) + '</button> ' +
				'<button type="button" class="cck-btn cck-btn--danger cck-btn--sm" data-cck-tpl-del="' + t.id + '">' + esc(tr('Delete')) + '</button></td></tr>';
		}).join('') || '<tr><td colspan="4" class="cck-empty">' + esc(templatesCache.length ? tr('No templates match the filter.') : tr('No tour templates yet — create the first one below.')) + '</td></tr>';
		tb.querySelectorAll('[data-cck-tpl-edit]').forEach(function (b) {
			b.onclick = function () { editTemplate(parseInt(b.dataset.cckTplEdit, 10)); };
		});
		tb.querySelectorAll('[data-cck-tpl-del]').forEach(function (b) {
			b.onclick = function () {
				confirmAsk(tr('Delete this tour template? Already generated tours for today and tomorrow stay unchanged.'), tr('Delete'))
					.then(function (yes) {
						if (!yes) { return; }
						api('/api/tour-templates/' + b.dataset.cckTplDel, { method: 'DELETE' }).then(function (r) {
							if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
							notify(tr('Template deleted.'));
							resetTourForm();
							loadTours();
						});
					});
			};
		});
	}
	if (window.CheckViewComponents) {
		CheckViewComponents.pillGroup(document.querySelector('[data-cck-tour-filters]'), function (v) {
			tourStatusFilter = v;
			renderTours();
		});
	}
	function loadTours() {
		api('/api/tour-templates').then(function (r) {
			var tb = document.querySelector('[data-cck-templates] tbody');
			if (!r.success) { tb.innerHTML = '<tr><td colspan="3" class="cck-empty">' + esc(r.message || tr('No access.')) + '</td></tr>'; return; }
			templatesCache = (r.data && r.data.templates) || [];
			// keep the board's template-name cache fresh — it is built once
			// from this same endpoint and would otherwise go stale after
			// creating or renaming a template in this session.
			templateNames = {};
			templatesCache.forEach(function (t) { templateNames[t.id] = t.name; });
			renderTours();
		});
	}
	var PRESETS = null; // §7.2 Tageszeit presets — provider-configured via settings
	function loadPresets() {
		if (PRESETS) { return PRESETS; }
		PRESETS = api('/api/settings').then(function (r) {
			var s2 = (r.data && r.data.settings) || {};
			return {
				early: s2.preset_early || '360-540', morning: s2.preset_morning || '540-720',
				noon: s2.preset_noon || '720-840', afternoon: s2.preset_afternoon || '840-1020',
				evening: s2.preset_evening || '1020-1200',
			};
		}).catch(function () { PRESETS = null; return {}; });
		return PRESETS;
	}
	var clientChoices = null; // cached name->id list; stops pick by name, never raw IDs
	function clientOptions() {
		if (!clientChoices) {
			clientChoices = api('/api/clients').then(function (r) {
				return (r.success && r.data && r.data.clients) || [];
			}).catch(function () { clientChoices = null; return []; });
		}
		return clientChoices;
	}
	// One stop row per click; every row carries a remove button so a stray
	// "Add stop" never forces a full form reset. addStopRow(stop) also seeds
	// rows when editing an existing template.
	function addStopRow(stop) {
		var box = document.querySelector('[data-cck-tour-stops]');
		var row = document.createElement('div');
		row.className = 'cck-tstop';
		row.innerHTML =
			'<label class="cck-field"><span class="cck-field__label">' + esc(tr('Client')) + '</span><select name="ts_client"><option value="">' + esc(tr('Pick a client…')) + '</option></select></label>' +
			'<label class="cck-field"><span class="cck-field__label">' + esc(tr('Kind')) + '</span><select name="ts_kind"><option value="client">' + esc(tr('Client')) + '</option><option value="external">' + esc(tr('External')) + '</option></select></label>' +
			'<label class="cck-field"><span class="cck-field__label">' + esc(tr('Time of day')) + '</span><select name="ts_preset">' +
				'<option value="early">' + esc(tr('early morning')) + '</option>' +
				'<option value="morning" selected>' + esc(tr('morning')) + '</option>' +
				'<option value="noon">' + esc(tr('midday')) + '</option>' +
				'<option value="afternoon">' + esc(tr('afternoon')) + '</option>' +
				'<option value="evening">' + esc(tr('evening')) + '</option>' +
				'<option value="custom">' + esc(tr('custom time…')) + '</option>' +
			'</select></label>' +
			'<select name="ts_timekind" class="cck-tstop__timekind" hidden aria-label="' + esc(tr('Time rule')) + '"><option value="window">' + esc(tr('Window')) + '</option><option value="fixed">' + esc(tr('Fixed')) + '</option><option value="deadline">' + esc(tr('Deadline')) + '</option></select>' +
			'<input name="ts_from" type="time" aria-label="' + esc(tr('From')) + '" hidden>' +
			'<input name="ts_to" type="time" aria-label="' + esc(tr('To / deadline')) + '" hidden>' +
			'<input name="ts_tol" type="number" min="0" max="240" placeholder="' + esc(tr('Tolerance min')) + '" inputmode="numeric" hidden aria-label="' + esc(tr('Tolerance minutes')) + '">' +
			'<input name="ts_loc" maxlength="190" placeholder="' + esc(tr('Location (external)')) + '" hidden aria-label="' + esc(tr('Location')) + '">' +
			'<button type="button" class="cck-btn cck-btn--ghost cck-btn--sm cck-tstop__rm" aria-label="' + esc(tr('Remove stop')) + '">×</button>';
		box.appendChild(row);
		row.querySelector('.cck-tstop__rm').onclick = function () { row.remove(); };
		// Preset → fills the window; "custom" reveals explicit times + rule.
		var presetSel = row.querySelector('[name=ts_preset]');
		var applyPreset = function () {
			var custom = presetSel.value === 'custom';
			row.querySelector('[name=ts_timekind]').hidden = !custom;
			row.querySelector('[name=ts_from]').hidden = !custom;
			row.querySelector('[name=ts_to]').hidden = !custom;
			row.querySelector('[name=ts_tol]').hidden = !custom;
			if (!custom) {
				row.querySelector('[name=ts_timekind]').value = 'window';
				loadPresets().then(function (p) {
					var range = (p[presetSel.value] || '540-720').split('-');
					row.querySelector('[name=ts_from]').value = fmtMin(parseInt(range[0], 10));
					row.querySelector('[name=ts_to]').value = fmtMin(parseInt(range[1], 10));
				});
			}
		};
		var applyStop = function () {
			if (!stop) { applyPreset(); return; }
			presetSel.value = 'custom';
			row.querySelector('[name=ts_timekind]').hidden = false;
			row.querySelector('[name=ts_from]').hidden = false;
			row.querySelector('[name=ts_to]').hidden = false;
			row.querySelector('[name=ts_tol]').hidden = false;
			row.querySelector('[name=ts_timekind]').value = stop.timeKind || 'window';
			row.querySelector('[name=ts_from]').value = fmtMin(stop.winStart || 0);
			row.querySelector('[name=ts_to]').value = fmtMin(stop.winEnd || 0);
			row.querySelector('[name=ts_tol]').value = stop.toleranceMin === null || stop.toleranceMin === undefined ? '' : String(stop.toleranceMin);
		};
		var kindSel = row.querySelector('[name=ts_kind]');
		var applyKind = function () {
			var ext = kindSel.value === 'external';
			row.querySelector('[name=ts_client]').closest('label').hidden = ext;
			row.querySelector('[name=ts_loc]').hidden = !ext;
		};
		if (stop) {
			kindSel.value = stop.kind === 'external' ? 'external' : 'client';
			row.querySelector('[name=ts_loc]').value = stop.locText || '';
		}
		clientOptions().then(function (clients) {
			var sel = row.querySelector('[name=ts_client]');
			clients.forEach(function (c) {
				var o = document.createElement('option');
				o.value = String(c.id);
				o.textContent = c.name + ' (' + c.id + ')';
				sel.appendChild(o);
			});
			if (clients.length === 0) { sel.options[0].textContent = tr('Add clients first.'); }
			if (stop && stop.clientId) { sel.value = String(stop.clientId); }
		});
		presetSel.addEventListener('change', applyPreset);
		kindSel.addEventListener('change', applyKind);
		applyStop(); applyKind();
	}
	document.querySelector('[data-cck-tour-add-stop]').addEventListener('click', function () { addStopRow(null); });
	// Edit mode: edit_id carries the template id + base revision for CAS.
	var editingTemplate = null;
	function resetTourForm() {
		editingTemplate = null;
		var f = document.querySelector('[data-cck-tour-form]');
		f.elements.edit_id.value = '';
		f.reset();
		clearFormErrors(f);
		document.querySelector('[data-cck-tour-stops]').innerHTML = '';
		document.querySelector('[data-cck-refpick="lead_ref"]')._refpick.clear();
		document.querySelector('[data-cck-tour-submit]').textContent = tr('Save tour template');
		document.querySelector('[data-cck-tour-form-title]').textContent = tr('New tour template');
		document.querySelector('[data-cck-tour-cancel]').hidden = true;
	}
	function editTemplate(id) {
		var t = templatesCache.find(function (x) { return x.id === id; });
		if (!t) { return; }
		resetTourForm();
		editingTemplate = t;
		var f = document.querySelector('[data-cck-tour-form]');
		f.elements.edit_id.value = String(t.id);
		f.elements.name.value = t.name || '';
		String(t.weekdaySet || '').split(',').forEach(function (d) {
			var cb = f.querySelector('input[name=wd][value="' + d.trim() + '"]');
			if (cb) { cb.checked = true; }
		});
		document.querySelector('[data-cck-refpick="lead_ref"]')._refpick.set(t.leadRef || '', t.leadRefLabel || t.leadRef || '');
		f.elements.status.value = t.status || 'draft';
		(t.stops || []).forEach(function (s) { addStopRow(s); });
		document.querySelector('[data-cck-tour-submit]').textContent = tr('Update tour template');
		document.querySelector('[data-cck-tour-form-title]').textContent = tr('Edit tour template');
		document.querySelector('[data-cck-tour-cancel]').hidden = false;
		f.scrollIntoView({ block: 'start' });
	}
	document.querySelector('[data-cck-tour-cancel]').addEventListener('click', resetTourForm);
	document.querySelector('[data-cck-tour-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = e.target;
		var stops = Array.prototype.slice.call(document.querySelectorAll('.cck-tstop')).map(function (row, i) {
			var cid = row.querySelector('[name=ts_client]').value;
			var kind = row.querySelector('[name=ts_kind]').value;
			return {
				client_id: cid === '' ? null : parseInt(cid, 10),
				kind: kind,
				time_kind: row.querySelector('[name=ts_timekind]').value,
				win_start: toMin(row.querySelector('[name=ts_from]').value),
				win_end: toMin(row.querySelector('[name=ts_to]').value),
				tolerance_min: row.querySelector('[name=ts_tol]').value === '' ? null : parseInt(row.querySelector('[name=ts_tol]').value, 10),
				loc_text: kind === 'external' ? row.querySelector('[name=ts_loc]').value : null,
				order_hint: i,
			};
		});
		var weekdays = Array.prototype.slice.call(f.querySelectorAll('input[name=wd]:checked')).map(function (el) { return parseInt(el.value, 10); });
		var editId = f.elements.edit_id.value;
		var body = {
			name: f.elements.name.value, weekday_set: weekdays,
			lead_ref: document.querySelector('[data-cck-refpick="lead_ref"]')._refpick.value || null,
			status: f.elements.status.value, stops: stops,
		};
		var url = '/api/tour-templates';
		if (editId !== '') {
			url += '/' + encodeURIComponent(editId);
			body.base_revision = editingTemplate ? editingTemplate.revision : 0;
		}
		withBusy(submitBtn(f), function () {
			return api(url, { method: editId === '' ? 'POST' : 'PUT', body: JSON.stringify(body) }).then(function (r) {
				if (r.success) { resetTourForm(); notify(tr('Tour saved.')); loadTours(); }
				else { reportForm(f, r); }
			});
		});
	});
	function toMin(hhmm) {
		if (!hhmm) { return 0; }
		var p = hhmm.split(':');
		return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
	}

	// ---------------- members + absences ----------------
	function showPairCard(code) {
		var card = document.querySelector('[data-cck-paircard]');
		// OC.getProtocol/getHost/getWebroot don't exist on NC35 — derive the
		// instance base URL from the canonical app route instead.
		var server = OC.generateUrl('/apps/carecheck').replace(/\/apps\/carecheck$/, '');
		var payload = 'cck://pair?server=' + encodeURIComponent(server) + '&code=' + encodeURIComponent(code);
		card.querySelector('[data-cck-pair-qr]').src = window.CareCheckQr.toDataUrl(payload, 220);
		card.querySelector('[data-cck-pair-text]').textContent = code;
		card.hidden = false;
		card.scrollIntoView({ block: 'nearest' });
	}
	document.querySelector('[data-cck-paircard-done]').addEventListener('click', function () {
		document.querySelector('[data-cck-paircard]').hidden = true;
	});
	var membersCache = [];
	var membersFilter = { q: '', qual: '' };
	function renderMembers() {
		var tb = document.querySelector('[data-cck-members] tbody');
		var count = document.querySelector('[data-cck-members-count]');
		var q = (membersFilter.q || '').toLowerCase();
		var rows = membersCache.filter(function (m) {
			if (membersFilter.qual && m.qualification !== membersFilter.qual) { return false; }
			if (q && (m.displayName || '').toLowerCase().indexOf(q) < 0) { return false; }
			return true;
		});
		if (count) {
			count.textContent = rows.length === membersCache.length
				? rows.length + ' ' + tr('members')
				: rows.length + ' / ' + membersCache.length + ' ' + tr('members');
		}
		tb.innerHTML = rows.map(function (m) {
			var nameCell = '<td data-cell="' + esc(tr('Name')) + '"><strong>' + esc(m.displayName) + '</strong>' +
				(m.active ? '' : ' <span class="cck-badge cck-badge--info">' + esc(tr('Inactive')) + '</span>') + '</td>';
			var actions = m.active
				? '<button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-member-edit="' + m.id + '">' + esc(tr('Edit')) + '</button> ' +
				'<button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-pair="' + m.id + '">' + esc(tr('Pair code')) + '</button> ' +
				'<button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-practice="' + m.id + '">' + esc(tr('Practice tour')) + '</button> ' +
				'<button type="button" class="cck-btn cck-btn--danger cck-btn--sm" data-cck-deact="' + m.id + '">' + esc(tr('Deactivate')) + '</button>'
				: '';
			return '<tr>' + nameCell +
				'<td data-cell="' + esc(tr('Qualification')) + '">' + qualLabel(m.qualification) + '</td>' +
				'<td data-cell="' + esc(tr('Team')) + '"><select class="cck-team-sel" data-cck-member-team="' + m.id + '" aria-label="' + esc(tr('Team')) + '">' + teamOptions(m.teamId) + '</select></td>' +
				'<td>' + actions + '</td></tr>';
		}).join('') || '<tr><td colspan="4" class="cck-empty">' + esc(membersCache.length ? tr('No members match the filter.') : tr('No members yet — add the first one below.')) + '</td></tr>';
		tb.querySelectorAll('[data-cck-member-team]').forEach(function (sel) {
			bindTeamSelect(sel, 'members', parseInt(sel.dataset.cckMemberTeam, 10));
		});
		tb.querySelectorAll('[data-cck-member-edit]').forEach(function (b) {
			b.onclick = function () { editMember(parseInt(b.dataset.cckMemberEdit, 10)); };
		});
		tb.querySelectorAll('[data-cck-pair]').forEach(function (b) {
			b.onclick = function () {
				withBusy(b, function () {
					return api('/api/members/' + b.dataset.cckPair + '/pair-code', { method: 'POST' }).then(function (r) {
						if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
						showPairCard(r.data.code);
					});
				});
			};
		});
		tb.querySelectorAll('[data-cck-practice]').forEach(function (b) {
			b.onclick = function () {
				withBusy(b, function () {
					return api('/api/practice-tour', { method: 'POST', body: JSON.stringify({ member_id: b.dataset.cckPractice }) }).then(function (r) {
						notify(r.success ? tr('Practice tour created.') : (r.message || tr('Invalid value.')), !r.success);
					});
				});
			};
		});
		tb.querySelectorAll('[data-cck-deact]').forEach(function (b) {
			b.onclick = function () {
				confirmAsk(tr('Deactivate this member? Their device is unpaired and they keep no access.'), tr('Deactivate'))
					.then(function (yes) {
						if (!yes) { return; }
						api('/api/members/' + b.dataset.cckDeact + '/deactivate', { method: 'POST' }).then(function (r) {
							if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
							loadMembers();
						});
					});
			};
		});
	}
	var editingMemberId = null;
	function resetMemberForm() {
		editingMemberId = null;
		var f = document.querySelector('[data-cck-member-form]');
		f.elements.edit_id.value = '';
		f.reset();
		clearFormErrors(f);
		document.querySelector('[data-cck-member-submit]').textContent = tr('Add member');
		document.querySelector('[data-cck-member-form-title]').textContent = tr('Add member');
		document.querySelector('[data-cck-member-cancel]').hidden = true;
	}
	function editMember(id) {
		var m = membersCache.find(function (x) { return x.id === id; });
		if (!m) { return; }
		resetMemberForm();
		editingMemberId = id;
		var f = document.querySelector('[data-cck-member-form]');
		f.elements.edit_id.value = String(id);
		f.elements.display_name.value = m.displayName || '';
		f.elements.qualification.value = m.qualification || 'hilfskraft';
		document.querySelector('[data-cck-member-submit]').textContent = tr('Save changes');
		document.querySelector('[data-cck-member-form-title]').textContent = tr('Edit member');
		document.querySelector('[data-cck-member-cancel]').hidden = false;
		f.scrollIntoView({ block: 'start' });
		f.elements.display_name.focus();
	}
	if (window.CheckViewComponents) {
		CheckViewComponents.bindListFilter(document.querySelector('[data-cck-members-filter]'), function (v) {
			membersFilter = { q: v.q || '', qual: v.qual || '' };
			renderMembers();
		});
	}
	function loadMembers() {
		api('/api/members').then(function (r) {
			var tb = document.querySelector('[data-cck-members] tbody');
			if (!r.success) { tb.innerHTML = '<tr><td colspan="4" class="cck-empty">' + esc(r.message || tr('No access.')) + '</td></tr>'; return; }
			membersCache = (r.data && r.data.members) || [];
			loadTeams().then(renderMembers);
		});
		loadAbsences();
	}
	function loadAbsences() {
		api('/api/absences').then(function (r) {
			var el = document.querySelector('[data-cck-absences]');
			if (!r.success) { el.innerHTML = '<li class="cck-empty">' + esc(r.message || tr('No access.')) + '</li>'; return; }
			el.innerHTML = ((r.data && r.data.absences) || []).map(function (a) {
				return '<li><strong>' + esc(a.staffLabel || a.staffRef) + '</strong> ' + esc(a.from) + ' → ' + esc(a.until) + ' (' + esc(tokenLabel(ABSENCE_KIND, a.kind)) + ')' +
					' <button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-absc="' + a.id + '">' + esc(tr('Cancel')) + '</button></li>';
			}).join('') || '<li class="cck-empty">' + esc(tr('No absences marked.')) + '</li>';
			el.querySelectorAll('[data-cck-absc]').forEach(function (b) {
				b.onclick = function () {
					confirmAsk(tr('Cancel this absence?'), tr('Cancel'))
						.then(function (yes) {
							if (!yes) { return; }
							api('/api/absences/' + b.dataset.cckAbsc, { method: 'DELETE' }).then(function (r) {
								if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
								loadAbsences();
							});
						});
				};
			});
		});
	}
	// Forgiving default: same-day absence is the common case — picking
	// "From" fills "Until" so a single-day sick note is one date, not two.
	(function () {
		var af = document.querySelector('[data-cck-absence-form]');
		var from = af.querySelector('[name=from]');
		var until = af.querySelector('[name=until]');
		from.addEventListener('change', function () {
			if (from.value && !until.value) { until.value = from.value; }
		});
	})();
	document.querySelector('[data-cck-absence-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var f = new FormData(e.target);
		withBusy(submitBtn(e.target), function () {
			return api('/api/absences', { method: 'POST', body: JSON.stringify({ staff_ref: document.querySelector('[data-cck-refpick="staff_ref"]')._refpick.value, from: f.get('from'), until: f.get('until'), kind: f.get('kind') }) })
				.then(function (r) {
					if (r.success) { e.target.reset(); loadAbsences(); }
					else { reportForm(e.target, r); }
				});
		});
	});
	document.querySelector('[data-cck-member-cancel]').addEventListener('click', resetMemberForm);
	document.querySelector('[data-cck-member-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var form = e.target;
		var f = new FormData(form);
		var editId = form.elements.edit_id.value;
		var body = JSON.stringify({ display_name: f.get('display_name'), qualification: f.get('qualification') });
		withBusy(submitBtn(form), function () {
			return api(editId === '' ? '/api/members' : '/api/members/' + encodeURIComponent(editId),
				{ method: editId === '' ? 'POST' : 'PUT', body: body })
				.then(function (r) {
					if (r.success) { resetMemberForm(); notify(editId === '' ? tr('Member added.') : tr('Saved.')); loadMembers(); }
					else { reportForm(form, r); }
				});
		});
	});

	// ---------------- catalog ----------------
	var catalogCache = [];
	var catalogFilter = { q: '', qual: '' };
	function renderCatalog() {
		var tb = document.querySelector('[data-cck-catalog] tbody');
		var count = document.querySelector('[data-cck-catalog-count]');
		var q = (catalogFilter.q || '').toLowerCase();
		var rows = catalogCache.filter(function (t) {
			if (catalogFilter.qual && t.requiresQual !== catalogFilter.qual) { return false; }
			if (q && (t.code || '').toLowerCase().indexOf(q) < 0 && (t.label || '').toLowerCase().indexOf(q) < 0) { return false; }
			return true;
		});
		if (count) {
			count.textContent = rows.length === catalogCache.length
				? rows.length + ' ' + tr('tasks')
				: rows.length + ' / ' + catalogCache.length + ' ' + tr('tasks');
		}
		tb.innerHTML = rows.map(function (t) {
			return '<tr><td data-cell="' + esc(tr('Code')) + '"><code>' + esc(t.code) + '</code></td>' +
				'<td data-cell="' + esc(tr('Label')) + '">' + esc(t.icon ? t.icon + ' ' : '') + esc(t.label) + '</td>' +
				'<td data-cell="' + esc(tr('Qualification')) + '">' + qualLabel(t.requiresQual) + '</td>' +
				'<td><button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-cat-edit="' + t.id + '">' + esc(tr('Edit')) + '</button></td></tr>';
		}).join('') || '<tr><td colspan="4" class="cck-empty">' + esc(catalogCache.length ? tr('No tasks match the filter.') : tr('No catalog tasks yet — add the first one below.')) + '</td></tr>';
		tb.querySelectorAll('[data-cck-cat-edit]').forEach(function (b) {
			b.onclick = function () { editCatalogTask(parseInt(b.dataset.cckCatEdit, 10)); };
		});
	}
	var editingCatalogId = null;
	function resetCatalogForm() {
		editingCatalogId = null;
		var f = document.querySelector('[data-cck-catalog-form]');
		f.elements.edit_id.value = '';
		f.reset();
		clearFormErrors(f);
		f.elements.code.disabled = false;
		document.querySelector('[data-cck-catalog-submit]').textContent = tr('Add task');
		document.querySelector('[data-cck-catalog-form-title]').textContent = tr('Add task');
		document.querySelector('[data-cck-catalog-cancel]').hidden = true;
	}
	function editCatalogTask(id) {
		var t = catalogCache.find(function (x) { return x.id === id; });
		if (!t) { return; }
		resetCatalogForm();
		editingCatalogId = id;
		var f = document.querySelector('[data-cck-catalog-form]');
		f.elements.edit_id.value = String(id);
		f.elements.code.value = t.code || '';
		// code is the plan reference — immutable once created.
		f.elements.code.disabled = true;
		f.elements.label.value = t.label || '';
		f.elements.icon.value = t.icon || '';
		f.elements.requires_qual.value = t.requiresQual || '';
		document.querySelector('[data-cck-catalog-submit]').textContent = tr('Save changes');
		document.querySelector('[data-cck-catalog-form-title]').textContent = tr('Edit task');
		document.querySelector('[data-cck-catalog-cancel]').hidden = false;
		f.scrollIntoView({ block: 'start' });
		f.elements.label.focus();
	}
	if (window.CheckViewComponents) {
		CheckViewComponents.bindListFilter(document.querySelector('[data-cck-catalog-filter]'), function (v) {
			catalogFilter = { q: v.q || '', qual: v.qual || '' };
			renderCatalog();
		});
	}
	function loadCatalog() {
		api('/api/catalog').then(function (r) {
			var tb = document.querySelector('[data-cck-catalog] tbody');
			if (!r.success) { tb.innerHTML = '<tr><td colspan="3" class="cck-empty">' + esc(r.message || tr('No access.')) + '</td></tr>'; return; }
			catalogCache = (r.data && r.data.tasks) || [];
			renderCatalog();
		});
	}
	document.querySelector('[data-cck-catalog-cancel]').addEventListener('click', resetCatalogForm);
	document.querySelector('[data-cck-catalog-form]').addEventListener('submit', function (e) {
		e.preventDefault();
		var form = e.target;
		var f = new FormData(form);
		var editId = form.elements.edit_id.value;
		var body = { label: f.get('label'), icon: f.get('icon') || null, requires_qual: f.get('requires_qual') || null };
		if (editId === '') { body.code = f.get('code'); }
		withBusy(submitBtn(form), function () {
			return api(editId === '' ? '/api/catalog' : '/api/catalog/' + encodeURIComponent(editId),
				{ method: editId === '' ? 'POST' : 'PUT', body: JSON.stringify(body) })
				.then(function (r) {
					if (r.success) { resetCatalogForm(); notify(editId === '' ? tr('Task added.') : tr('Saved.')); loadCatalog(); }
					else { reportForm(form, r); }
				});
		});
	});

	// ---------------- inbox (+ review queue, spec UX-13) ----------------
	function loadReviews() {
		api('/api/review-queue').then(function (r) {
			var panel = document.querySelector('[data-cck-review-panel]');
			var el = document.querySelector('[data-cck-reviews]');
			var badge = document.querySelector('[data-cck-review-badge]');
			var items = (r.success && r.data && r.data.reviews) || [];
			if (badge) { badge.hidden = items.length === 0; badge.textContent = String(items.length); }
			if (panel) { panel.hidden = !r.success || items.length === 0; }
			el.innerHTML = items.map(function (v) {
				return '<div class="cck-notice cck-notice--high">' +
					'<strong>' + esc(v.clientName || ('#' + v.clientId)) + '</strong> ' +
					'<span>' + esc(v.actorLabel || v.actorRef) + '</span> ' +
					'<span class="cck-chip">' + esc(tr('rejected')) + '</span>' +
					'<p class="cck-notice__text">' + esc(v.rejectReason || '') + '</p>' +
					' <a class="cck-link" href="#/client/' + v.clientId + '">' + esc(tr('Open client')) + '</a>' +
					' <button type="button" class="cck-btn cck-btn--primary cck-btn--sm" data-cck-resolve="' + v.id + '">' + esc(tr('Resolve')) + '</button>' +
					'</div>';
			}).join('');
			el.querySelectorAll('[data-cck-resolve]').forEach(function (b) {
				b.onclick = function () { openResolveDialog(b.dataset.cckResolve); };
			});
		});
	}
	var noticesCache = [];
	var noticeFilter = 'all';
	function renderNotices() {
		var el = document.querySelector('[data-cck-notices]');
		var badge = document.querySelector('[data-cck-inbox-badge]');
		var notices = noticesCache.filter(function (n) {
			if (noticeFilter === 'open') { return n.state === 'open' || n.state === 'escalated'; }
			if (noticeFilter === 'ack') { return n.state === 'acked'; }
			return true;
		});
		var open = noticesCache.filter(function (n) { return n.state === 'open' || n.state === 'escalated'; }).length;
		if (badge) { badge.hidden = open === 0; badge.textContent = String(open); }
		el.innerHTML = notices.map(function (n) {
				var chips = '';
				if (n.state === 'open' || n.state === 'escalated') { chips += '<span class="cck-chip cck-chip--open">' + esc(tr('open')) + '</span> '; }
				if (n.escalated) { chips += '<span class="cck-chip cck-chip--escalated">' + esc(tr('escalated')) + '</span> '; }
				if (n.resolution === 'error') { chips += '<span class="cck-chip">' + esc(tr('sent in error')) + '</span> '; }
				if (n.state === 'acked' && n.resolution !== 'error') { chips += '<span class="cck-chip">' + esc(tr('acknowledged')) + '</span> '; }
				return '<div class="cck-notice cck-notice--' + esc(n.priority) + '"><strong>' + esc(tokenLabel(NOTICE_KIND, n.kind)) + '</strong> ' +
					'<span>' + esc(n.actorLabel || n.actorRef) + '</span> ' + chips +
					(n.note ? ' <p class="cck-notice__text">' + esc(n.note) + '</p>' : '') +
					(n.state === 'open' || n.state === 'escalated' ? ' <button type="button" class="cck-btn cck-btn--secondary cck-btn--sm" data-cck-ack="' + n.id + '">' + esc(tr('Acknowledge')) + '</button>' : '') +
					'</div>';
		}).join('') || '<p class="cck-empty">' + esc(noticesCache.length ? tr('No notices match the filter.') : tr('Inbox is empty.')) + '</p>';
		el.querySelectorAll('[data-cck-ack]').forEach(function (b) {
			b.onclick = function () {
				withBusy(b, function () {
					return api('/api/notices/' + b.dataset.cckAck + '/ack', { method: 'POST' }).then(function (r) {
						if (!r.success) { notify(r.message || tr('Failed.'), true); return; }
						loadInbox();
					});
				});
			};
		});
	}
	if (window.CheckViewComponents) {
		CheckViewComponents.pillGroup(document.querySelector('[data-cck-notice-filters]'), function (v) {
			noticeFilter = v;
			renderNotices();
		});
	}
	function loadInbox() {
		loadReviews();
		api('/api/notices').then(function (r) {
			var el = document.querySelector('[data-cck-notices]');
			if (!r.success) { el.innerHTML = '<p class="cck-empty">' + esc(r.message || tr('No access.')) + '</p>'; return; }
			noticesCache = (r.data && r.data.notices) || [];
			renderNotices();
		});
	}
	// inbox poll ≤60s — incidents surface near-realtime without websockets (spec §9)
	setInterval(function () {
		if ((location.hash.replace(/^#\/?/, '') || 'board').split('/')[0] === 'inbox') { loadInbox(); }
	}, 60000);

	// ---------------- settings ----------------
	function dlPairs(el, pairs) {
		el.innerHTML = pairs.filter(function (p) { return p[1] !== undefined; }).map(function (p) {
			return '<dt>' + esc(p[0]) + '</dt><dd>' + esc(p[1]) + '</dd>';
		}).join('') || '<dd class="cck-empty">' + esc(tr('No data.')) + '</dd>';
	}
	function loadLicense() {
		var panel = document.querySelector('[data-cck-license-panel]');
		var dl = document.querySelector('[data-cck-license-state]');
		api('/api/license').then(function (r) {
			if (!r.success) { panel.hidden = true; return; }
			panel.hidden = false;
			var lic = (r.data && r.data.license) || null;
			dlPairs(dl, lic === null ? [[tr('State'), tr('No license — companion app is locked')]] : [
				[tr('State'), lic.active ? tr('Active') : tr('Inactive')],
				[tr('Mobile seats'), String(lic.mobileSeats)],
				[tr('Seats in use'), String(r.data.seatsUsed)],
				[tr('Valid until'), lic.validUntil || '—'],
				[tr('Customer'), lic.customerId || '—'],
				[tr('Key applied'), lic.keyAppliedAt || '—'],
			]);
		});
	}
	// Settings section bodies live in <template>s — delegated handlers bind clones.
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (f && f.hasAttribute && f.hasAttribute('data-cck-license-form')) {
			e.preventDefault();
			withBusy(submitBtn(f), function () {
				return api('/api/license', { method: 'POST', body: JSON.stringify({ license_key: f.elements.license_key.value }) })
					.then(function (r) {
						if (r.success) { clearFormErrors(f); f.reset(); notify(tr('License applied.')); loadLicense(); }
						else { reportForm(f, r); }
					});
			});
		}
	});
	document.addEventListener('click', function (e) {
		var b = e.target && e.target.closest ? e.target.closest('[data-cck-license-clear]') : null;
		if (b) {
			confirmAsk(tr('Remove the license? The companion app locks for everyone.'), tr('Remove license'))
				.then(function (yes) {
					if (!yes) { return; }
					api('/api/license', { method: 'DELETE' }).then(function (r) {
						notify(r.success ? tr('License removed.') : (r.message || tr('Invalid value.')), !r.success);
						loadLicense();
					});
				});
		}
	});
	// Machine tokens must never reach the UI — map before render.
	var LICENSE_STATE = { none: 'No license — companion app is locked', active: 'Active', inactive: 'Inactive' };
	function loadDiagnostics() {
		var panel = document.querySelector('[data-cck-diag-panel]');
		var dl = document.querySelector('[data-cck-diag-state]');
		api('/api/diagnostics').then(function (r) {
			if (!r.success) { panel.hidden = true; return; }
			panel.hidden = false;
			var d = (r.data && r.data.diagnostics) || {};
			dlPairs(dl, [
				[tr('App version'), d.appVersion],
				[tr('Cron'), d.cronOk ? tr('OK') : tr('stale — check the instance cron')],
				[tr('Paired devices'), d.pairedDevices !== undefined ? String(d.pairedDevices) : '—'],
				[tr('Outbox backlog'), d.pendingOutbox !== undefined ? String(d.pendingOutbox) : '—'],
				[tr('License'), tokenLabel(LICENSE_STATE, d.licenseState)],
			]);
		});
	}
	// Submitting a settings form before the first hydrate POSTs blank fields —
	// the server rightly rejects with "Validation failed." (mobile e2e flake).
	// Settings submits stay disabled until hydration lands, then enable.
	var settingsHydrated = null;
	var settingsReady = false;
	// Settings UI speaks HH:MM-HH:MM, the API stores minutes ("540-720") —
	// convert at both boundaries so admins never touch minute math.
	function presetToUi(v) {
		var m = /^(\d{1,4})-(\d{1,4})$/.exec(v || '');
		return m ? fmtMin(parseInt(m[1], 10)) + '-' + fmtMin(parseInt(m[2], 10)) : v;
	}
	function presetToApi(v) {
		var m = /^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/.exec(v || '');
		if (!m) { return v; }
		return String(parseInt(m[1], 10) * 60 + parseInt(m[2], 10)) + '-' +
			String(parseInt(m[3], 10) * 60 + parseInt(m[4], 10));
	}
	function ungateSettingsForms() {
		settingsReady = true;
		document.querySelectorAll('[data-cck-settings-form] [type="submit"]').forEach(function (b) { b.disabled = false; });
	}
	function loadSettings() {
		PRESETS = null; // keep the tour editor presets in sync after edits
		if (mountedSettings.companion) { loadLicense(); loadDiagnostics(); }
		var p = api('/api/settings').then(function (r) {
			if (!r.success) { return; }
			document.querySelectorAll('[data-cck-settings-form]').forEach(function (f) {
				Object.keys(r.data.settings).forEach(function (k) {
					var el = f.elements[k];
					if (!el) { return; }
					if (el.type === 'checkbox') { el.checked = r.data.settings[k] === '1'; }
					else { el.value = k.indexOf('preset_') === 0 ? presetToUi(r.data.settings[k]) : r.data.settings[k]; }
				});
			});
			// DS-2 access door — admin-only section (server enforces too).
			var isAdmin = r.data.settings.is_app_admin === '1';
			settingsAdmin = isAdmin;
			document.querySelectorAll('[data-cck-subnav="access"]').forEach(function (a) {
				a.hidden = !isAdmin;
			});
			var curHash = location.hash.replace(/^#\/?/, '') || '';
			if (!isAdmin && curHash === 'settings/access') {
				location.replace('#/settings/general');
			} else if (isAdmin && curHash === 'settings/access') {
				route(true); // admin confirmed — mount + show the door now
				loadTeams();
			}
			['allowed_users', 'allowed_groups', 'app_admins'].forEach(function (k) {
				var box = document.querySelector('[data-cck-refpick="' + k + '"]');
				if (box && box._refpick) { box._refpick.setList(r.data.settings[k] || [], r.data.settings[k + '_labels'] || []); }
			});
			ungateSettingsForms();
			return r;
		});
		if (!settingsHydrated) { settingsHydrated = p.finally(ungateSettingsForms); }
		return p;
	}
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!(f && f.hasAttribute && f.hasAttribute('data-cck-settings-form'))) { return; }
		e.preventDefault();
		var body = {};
		['seat_limit', 'provider_tz', 'device_rate_per_min', 'sla_minutes', 'tsa_url', 'retention_years', 'breakglass_hours_max', 'min_companion_version',
		 'preset_early', 'preset_morning', 'preset_noon', 'preset_afternoon', 'preset_evening'].forEach(function (k) {
			if (f.elements[k]) { body[k] = k.indexOf('preset_') === 0 ? presetToApi(f.elements[k].value) : f.elements[k].value; }
		});
		if (f.elements.gps_enabled) {
			body.gps_enabled = f.elements.gps_enabled.checked ? '1' : '0';
		}
		// Door fields are admin-only (DS-2) — the hidden section must not
		// submit them at all, or the server rightly flags them invalid.
		var accessBox = f.hasAttribute('data-cck-access') ? f : f.querySelector('[data-cck-access]');
		if (f.elements.access_mode && accessBox && !accessBox.hidden) {
			body.access_mode = f.elements.access_mode.value;
			['allowed_users', 'allowed_groups', 'app_admins'].forEach(function (k) {
				var box = f.querySelector('[data-cck-refpick="' + k + '"]');
				if (box && box._refpick) { body[k] = box._refpick.value; }
			});
		}
		var send = function () {
			withBusy(submitBtn(f), function () {
				return api('/api/settings', { method: 'POST', body: JSON.stringify(body) })
					.then(function (r) {
						if (r.success) { clearFormErrors(f); notify(tr('Saved.')); }
						else { reportForm(f, r); }
					});
			});
		};
		send();
	});

	window.addEventListener('hashchange', function () { route(); });
	route();
})();
