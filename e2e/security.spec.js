/**
 * ATLAS Lens 2 — security negative paths.
 * Unauthenticated API, missing requesttoken (CSRF), non-member actor →
 * uniform NOT_FOUND, restricted access-door lockout, public-share rate limit.
 * Every assertion is against the wire/DB, never the UI chrome.
 */
import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';

const COMPOSE = '../../docker-compose.yml';
const occ = (cmd, env = '') => execSync(
	`docker compose -f ${COMPOSE} exec -T ${env} -u www-data nextcloud php occ ${cmd}`,
	{ encoding: 'utf8' }).trim();

const ADMIN = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const STRANGER = { username: 'cck_stranger', password: 'Str4nger!Care2026' };
// Canonical uniform-404 payload (api-security §1). PHP serializes an empty
// fields map as [] — assert the contract semantically, not by JSON shape.
const expectNotFound = (j, url) => {
	expect(j.success, `${url} success`).toBe(false);
	expect(j.error, `${url} error`).toBe('NOT_FOUND');
	expect(j.message, `${url} message`).toBe('Not found.');
	expect(Object.keys(j.fields ?? {}).length, `${url} fields`).toBe(0);
};

test.beforeAll(() => {
	// Probe user: authenticated but no member row, no role group — the
	// cross-tenant actor every staff endpoint must refuse uniformly.
	const users = occ('user:list');
	if (!users.includes(STRANGER.username)) {
		occ(`user:add --password-from-env ${STRANGER.username}`, `-e OC_PASS=${STRANGER.password}`);
	}
	// Make sure a stale restricted-mode run never locks the lab out.
	occ('config:app:set carecheck access_mode --value=open');
});

test.describe('SECURITY', () => {
	test('anonymous API request returns no app data', async ({ playwright, baseURL }) => {
		const ctx = await playwright.request.newContext({ baseURL });
		const res = await ctx.get('/apps/carecheck/api/clients');
		const body = await res.text();
		// NC either 401s JSON or redirects to /login — neither may leak rows.
		expect(res.status()).not.toBe(500);
		expect(body).not.toContain('"success":true');
		expect(body).not.toMatch(/Klient|Mustermann/);
		await ctx.dispose();
	});

	test('state-changing POST without requesttoken is refused', async ({ page }) => {
		await login(page, ADMIN);
		const res = await page.context().request.post('/apps/carecheck/api/group-sessions', {
			headers: { 'Content-Type': 'application/json' },
			data: { title: 'csrf probe', client_ids: [1], idem_key: `csrf-${Date.now()}` },
		});
		expect(res.status()).toBeGreaterThanOrEqual(400);
		expect(res.status()).toBeLessThan(500);
		const j = await res.json().catch(() => ({}));
		expect(j.success).not.toBe(true);
	});

	test('non-member session gets uniform NOT_FOUND on every staff endpoint', async ({ page }) => {
		await login(page, STRANGER);
		// Session-authed API calls carry the requesttoken header (NC CSRF gate).
		const html = await (await page.context().request.get('/apps/carecheck/')).text();
		const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
		const request = page.context().request;
		const authed = (url) => request.get(url, { headers: { requesttoken: rt } });
		for (const url of [
			'/apps/carecheck/api/clients',
			'/apps/carecheck/api/diagnostics',
			'/apps/carecheck/api/members',
			'/apps/carecheck/api/notices',
			'/apps/carecheck/api/settings',
		]) {
			const res = await authed(url);
			expect(res.status(), `${url} status`).toBe(404);
			expectNotFound(await res.json(), url);
		}
		// A write too — stranger cannot mint state.
		const post = await request.post('/apps/carecheck/api/clients', {
			headers: { 'Content-Type': 'application/json', requesttoken: rt },
			data: { name: 'Intruder' },
		});
		expect(post.status()).toBe(404);
		expectNotFound(await post.json(), 'POST /clients');
	});

	test('nonexistent id for a staff user collapses to the same NOT_FOUND (no oracle)', async ({ page }) => {
		await login(page, ADMIN);
		const html = await (await page.context().request.get('/apps/carecheck/')).text();
		const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
		const res = await page.context().request.get('/apps/carecheck/api/clients/99999999', {
			headers: { requesttoken: rt },
		});
		expect(res.status()).toBe(404);
		expectNotFound(await res.json(), 'GET /clients/99999999');
	});

	test('restricted access_mode locks out a non-allowlisted user', async ({ page }) => {
		occ('config:app:set carecheck access_mode --value=restricted');
		occ('config:app:set carecheck allowed_users --value=\'["cck_smoke"]\'');
		try {
			await login(page, STRANGER);
			const request = page.context().request;
			// appconfig rides APCu (per-SAPI): the CLI write reaches FPM with a
			// short lag, so poll the observable status rather than sleeping.
			let status = 0;
			for (let i = 0; i < 40 && status !== 404; i++) {
				status = (await request.get('/apps/carecheck/')).status();
				if (status !== 404) await page.waitForTimeout(250);
			}
			// Uniform 404 — the page route must not leak that the app exists.
			expect(status).toBe(404);
			// nav_entry_access_leak: the OCS navigation endpoint (web menu +
			// mobile clients) must not advertise the app to excluded users.
			const nav = await request.get('/ocs/v2.php/core/navigation/apps?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
			});
			expect((await nav.json()).ocs.data.map((e) => e.id)).not.toContain('carecheck');
		} finally {
			occ('config:app:set carecheck access_mode --value=open');
			occ('config:app:delete carecheck allowed_users');
		}
	});

	test('open access_mode restores the menu entry', async ({ page }) => {
		// Counterpart to the restricted-mode assertion: with the default open
		// mode the entry must be present — guards the NavGate from failing
		// closed for everyone.
		await login(page, STRANGER);
		const request = page.context().request;
		// Poll: a preceding restricted-mode test's config writes ride APCu
		// with a per-SAPI lag.
		let ids = [];
		for (let i = 0; i < 40; i++) {
			const nav = await request.get('/ocs/v2.php/core/navigation/apps?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
			});
			ids = (await nav.json()).ocs.data.map((e) => e.id);
			if (ids.includes('carecheck')) break;
			await page.waitForTimeout(250);
		}
		expect(ids).toContain('carecheck');
	});

	test('public share view throttles token probing (AnonRateLimit)', async ({ playwright, baseURL }) => {
		const ctx = await playwright.request.newContext({ baseURL });
		const token = 'a'.repeat(48);
		const statuses = [];
		for (let i = 0; i < 25; i++) {
			const r = await ctx.get(`/apps/carecheck/share/${token}`);
			statuses.push(r.status());
		}
		// Every response is either the uniform not-found page or a throttle —
		// never a 500, never an oracle difference for real vs fake tokens.
		expect(statuses.every((s) => [404, 429].includes(s))).toBe(true);
		expect(statuses.filter((s) => s === 429).length).toBeGreaterThan(0);
		await ctx.dispose();
	});
});
