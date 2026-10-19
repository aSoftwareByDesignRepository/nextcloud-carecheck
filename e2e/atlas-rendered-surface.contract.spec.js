// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — rendered truth of each CareCheck surface:
 * list markers not reset by shell CSS, selects vertically centred (not sunken),
 * icons non-zero, controls not center-aligned by inherited shell styles.
 * CareCheck is a hash-routed SPA — each route gets its own pass.
 */
import { test, expect } from '@playwright/test';
import shared from '../../_shared/e2e/atlas-rendered-surface-contract.js';
import { login } from './helpers/auth.js';

const { assertAtlasRenderedSurface } = shared;
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };

// Client record (Klientenakte) lives behind a hash route that needs a real
// client id — seed one so the pinned-control check reaches the edit form.
let clientId = 0;

test.beforeAll(async ({ browser }) => {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	await login(page, CREDS);
	const request = page.context().request;
	const html = await (await request.get('/apps/carecheck/')).text();
	const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
	expect(rt).not.toBe('');
	const post = (path, data) => request.fetch(`/apps/carecheck${path}`, {
		method: 'POST',
		headers: { requesttoken: rt, 'Content-Type': 'application/json' },
		data,
	});
	const res = await post('/api/clients', { name: `RS Klient ${Date.now()}`, street: 'Renderweg 2' });
	const body = await res.json();
	expect(body.success, JSON.stringify(body)).toBe(true);
	clientId = body.data.id;
	// Member-row actions fit check needs an active member row plus a team
	// option long enough to expose an unbounded select (async-loaded option
	// widths drove the 2026-10-19 members table clip).
	const ts = Date.now();
	const team = await (await post('/api/teams', { name: `RS Overflow Team ${ts} mit extra langem Namen` })).json();
	expect(team.success, JSON.stringify(team)).toBe(true);
	const member = await (await post('/api/members', { display_name: `RS Mitglied ${ts}`, qualification: 'fachkraft' })).json();
	expect(member.success, JSON.stringify(member)).toBe(true);
	await ctx.close();
});

const SURFACES = [
	['#/board', 'coverage board'],
	['#/clients', 'clients'],
	['#/tours', 'tours'],
	['#/members', 'members'],
	['#/catalog', 'catalog'],
	['#/inbox', 'inbox'],
	['#/settings/general', 'settings general'],
	['#/settings/companion', 'settings companion'],
	['#/settings/access', 'settings access'],
	['#/settings/privacy', 'settings privacy'],
	['#/settings/support', 'settings support'],
	['#/getapp', 'get the app'],
];

/**
 * Intentionally marker-less lists: nav menu/sublist, chips, setup steps use
 * explicit list-style:none + custom row chrome. Content lists are NOT here.
 */
const LIST_ALLOW = [
	'.cck-nav',
	'.cck-chipbar',
	'.cck-nav-footer__menu',
	'.cck-refpick__chips',
	'.cck-refpick__list',
	'.cck-board',
	'.cck-uncovered',
	'.cck-absences',
	'.cck-journal',
	'.cck-getapp__features',
].join(', ');

test.beforeEach(async ({ page }) => {
	await login(page, CREDS);
});

test.describe('Rendered-surface contract', () => {
	for (const [hash, name] of SURFACES) {
		test(`ATLAS_RENDERED_SURFACE_CONTRACT ${name} (${hash})`, async ({ page }) => {
			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(`/apps/carecheck/${hash}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#app-content.cck-app .cck-view:not([hidden])', { timeout: 30_000 });
			await assertAtlasRenderedSurface(page, {
				content: '#app-content.cck-app',
				navExclude: '#app-navigation',
				listAllow: LIST_ALLOW,
			});
		});
	}

	// Member row actions must fit the scroll wrap at desktop width — a long
	// team name must not inflate the team column and push Edit/Pair code/
	// Practice tour/Deactivate behind horizontal scroll (learned 2026-10-19:
	// clipped_control_text_at_screen_edge + async_option_layout_shift).
	test('ATLAS_RENDERED_SURFACE_CONTRACT members row actions fit wrap @1280', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('[data-cck-members] tbody tr .cck-row-actions .cck-btn', { state: 'visible', timeout: 30_000 });
		const fit = await page.evaluate(() => {
			const wrap = document.querySelector('[data-cck-members]').closest('.cck-table-wrap');
			const wrapRight = wrap.getBoundingClientRect().right;
			const offenders = [];
			document.querySelectorAll('[data-cck-members] .cck-row-actions .cck-btn, [data-cck-members] .cck-team-sel').forEach((el) => {
				const r = el.getBoundingClientRect();
				if (r.right > wrapRight + 1) {
					offenders.push(`${el.className} right=${Math.round(r.right)} > wrap=${Math.round(wrapRight)}`);
				}
			});
			return { scrollWidth: wrap.scrollWidth, clientWidth: wrap.clientWidth, offenders };
		});
		expect(fit.offenders, JSON.stringify(fit)).toEqual([]);
		expect(fit.scrollWidth, `member table needs no horizontal scroll at 1280 (${JSON.stringify(fit)})`)
			.toBeLessThanOrEqual(fit.clientWidth + 1);
	});

	// Klientenakte — the screen that shipped 130px fields (core inputs.scss pin
	// vs. stretch-intended .cck-form-col). Needs the client seeded in beforeAll.
	test('ATLAS_RENDERED_SURFACE_CONTRACT client record (#/client)', async ({ page }) => {
		expect(clientId).toBeGreaterThan(0);
		await page.setViewportSize({ width: 1280, height: 800 });
		await page.goto(`/apps/carecheck/#/client/${clientId}`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#app-content.cck-app [data-cck-view="client"]:not([hidden])', { timeout: 30_000 });
		await page.waitForSelector('[data-cck-client-edit] input[name="name"]', { state: 'visible', timeout: 30_000 });
		await assertAtlasRenderedSurface(page, {
			content: '#app-content.cck-app',
			navExclude: '#app-navigation',
			listAllow: LIST_ALLOW,
		});
	});
});
