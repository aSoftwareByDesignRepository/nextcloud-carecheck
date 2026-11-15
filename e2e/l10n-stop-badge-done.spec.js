/**
 * Lens — l10n stop-badge msgid contract (defect 2026-11-15).
 *
 * The STOP_STATUS label cells are reached via `tr(s[1])` — dynamic table
 * lookup, invisible to literal-arg catalog scanners. A catalog regen once
 * dropped the lowercase msgid 'done' from every locale while the badge
 * still referenced it: every non-en locale rendered raw English 'done' on
 * a sealed surface. This spec seeds a real done stop, renders the board in
 * two locales, and asserts the localized word — never the English token.
 */
import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const COMPOSE = '../../docker-compose.yml';
const occ = (cmd) => execSync(
	`docker compose -f ${COMPOSE} exec -T -u www-data nextcloud php occ ${cmd}`,
	{ encoding: 'utf8' }).trim();

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const OUT = resolve(process.env.CCK_PROBE_OUT || '../../.cursor/atlas-farm-v3/artifacts/carecheck/probes');
const TS = Date.now();
let rt = '';
let deviceToken = '';

async function api(request, path, opts = {}) {
	const res = await request.fetch(`/apps/carecheck${path}`, {
		...opts,
		headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
	});
	return res.json();
}

test.beforeAll(async ({ browser }) => {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	await login(page, CREDS);
	const request = page.context().request;
	const html = await (await request.get('/apps/carecheck/')).text();
	rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
	expect(rt).not.toBe('');

	const m = await api(request, '/api/members', {
		method: 'POST',
		data: { display_name: `E2E Badge ${TS}`, qualification: 'fachkraft' },
	});
	expect(m.success).toBe(true);
	await api(request, '/api/settings', { method: 'POST', data: { seat_limit: '999' } });
	const code = (await api(request, `/api/members/${m.data.id}/pair-code`, { method: 'POST' })).data.code;
	const pair = await request.fetch('/apps/carecheck/api/v1/pair', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		data: { code, device_name: `e2e-badge-${TS}`, companion_version: '0.1.0' },
	}).then((r) => r.json());
	expect(pair.success, JSON.stringify(pair)).toBe(true);
	deviceToken = pair.data.deviceToken;
	const memberRef = `member:${m.data.id}`;

	const task = await api(request, '/api/catalog', {
		method: 'POST',
		data: { code: `e2e_badge_${TS}`.slice(0, 20), label: 'Badge check' },
	});
	expect(task.success).toBe(true);
	const c = await api(request, '/api/clients', {
		method: 'POST',
		data: { name: `E2E Badge Klient ${TS}`, street: 'E2E Weg 1', zip: '10115', city: 'Berlin' },
	});
	expect(c.success).toBe(true);
	const plan = await api(request, `/api/clients/${c.data.id}/plan/publish`, {
		method: 'POST',
		data: { base_revision: 1, tasks: [{ task_code: `e2e_badge_${TS}`.slice(0, 20), label: 'Badge check', value_required: false }] },
	});
	expect(plan.success).toBe(true);

	const weekday = ((new Date().getDay() + 6) % 7) + 1;
	const tpl = await api(request, '/api/tour-templates', {
		method: 'POST',
		data: {
			name: `E2E Badge Tour ${TS}`, weekday_set: [weekday], status: 'published',
			stops: [{ client_id: c.data.id, win_start: 360, win_end: 720, order_hint: 0, executor_ref: memberRef, kind: 'fixed' }],
		},
	});
	expect(tpl.success).toBe(true);
	await api(request, '/api/tours/generate', { method: 'POST' });

	const now = new Date();
	const stops = [];
	for (const d of [now, new Date(now.getTime() + 86400000)]) {
		const resp = await request.fetch(`/apps/carecheck/api/v1/tours/${d.toISOString().slice(0, 10)}`, {
			headers: { 'X-CCK-Device-Token': deviceToken },
		}).then((r) => r.json());
		stops.push(...(resp?.data?.tours ?? []).flatMap((t) => t.stops));
	}
	const stop = stops.find((s) => s.executorRef === memberRef);
	expect(stop).toBeTruthy();
	const dev = { 'X-CCK-Device-Token': deviceToken, 'Content-Type': 'application/json' };
	await request.fetch('/apps/carecheck/api/v1/visits/checkin', {
		method: 'POST', headers: dev,
		data: { tstop_id: stop.id, client_id: c.data.id, idem_key: `e2e-badge-ci-${TS}` },
	});
	const bundle = await request.fetch('/apps/carecheck/api/v1/visits', {
		method: 'POST', headers: dev,
		data: {
			tstop_id: stop.id, client_id: c.data.id, idem_key: `e2e-badge-bundle-${TS}`, sig_state: 'unable',
			tasks: [{ task_code: `e2e_badge_${TS}`.slice(0, 20), status: 'done' }],
		},
	}).then((r) => r.json());
	expect(bundle.success).toBe(true);
	await ctx.close();
});

test.afterAll(async ({ browser }) => {
	// seat hygiene: unpair so repeated runs never exhaust the license cap
	if (!deviceToken) { return; }
	const ctx = await browser.newContext();
	try {
		await ctx.request.delete('/apps/carecheck/api/v1/pair', {
			headers: { 'X-CCK-Device-Token': deviceToken },
		});
	} finally {
		await ctx.close();
	}
});

test.describe('L10N stop badge: done msgid renders localized', () => {
	for (const [locale, word] of [['de', 'erledigt'], ['pl', 'wykonane']]) {
		test(`${locale}: done badge shows "${word}", not the English wire token`, async ({ page }) => {
			occ(`user:setting cck_smoke core lang ${locale}`);
			try {
				await login(page, CREDS);
				await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' });
				await page.waitForSelector('#app-content .cck-view:not([hidden])', { timeout: 30_000 });
				const badge = page.locator('.cck-stop--done .cck-badge--success').first();
				await expect(badge).toBeVisible({ timeout: 30_000 });
				const text = (await badge.innerText()).trim();
				expect(text).toBe(word);
				expect(text).not.toBe('done');

				mkdirSync(OUT, { recursive: true });
				const dom = await page.locator('.cck-stop--done').first().evaluate((el) => el.outerHTML);
				writeFileSync(`${OUT}/l10n-stop-badge-done-${locale}.dom.html`, dom);
				await page.screenshot({ path: `${OUT}/l10n-stop-badge-done-${locale}.png`, fullPage: true });
			} finally {
				occ('user:setting cck_smoke core lang en');
			}
		});
	}
});
