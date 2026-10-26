// @ts-check
/**
 * ATLAS screenshot-matrix capture (design-system checklist §VISUAL gate).
 *
 * Drives every surface in e2e/screenshot-matrix.json through the declared
 * viewport × state × theme grid and writes PNGs into
 * .cursor/atlas-farm-v3/artifacts/carecheck/craft/web/. The companion gate
 * scripts/check-screenshot-matrix.py verifies completeness — a declared
 * matrix cell that produces no artifact fails the ready-gate craft stage.
 *
 * States:
 *   populated — seeded data visible (member+client+template+task+journal)
 *   empty     — deterministic empty render: far-future board date, or a
 *               filter query that matches nothing (list can't rely on DB)
 *   current   — state-invariant chrome (settings, getapp) or surfaces whose
 *               population is not staff-seedable (inbox: device-only notices)
 *
 * Run:
 *   npx playwright test e2e/capture-screenshot-matrix.spec.js --project=chromium
 */
import { test, expect } from '@playwright/test';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { login } from './helpers/auth.js';

const HERE = dirname(fileURLToPath(import.meta.url));
const MANIFEST = JSON.parse(readFileSync(join(HERE, 'screenshot-matrix.json'), 'utf8'));
const OUT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/carecheck', MANIFEST.outDir);
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const TS = Date.now();
const NO_MATCH = 'zz-nomatch-9x7q';
const EMPTY_DATE = '2099-06-15';
const TODAY = new Date().toISOString().slice(0, 10);

let rt = '';
let clientId = 0;

async function api(request, path, opts = {}) {
	const res = await request.fetch(`/apps/carecheck${path}`, {
		...opts,
		headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
	});
	return res.json();
}

/**
 * Apply a user theme client-side (theme--* / data-theme-* / color-scheme) —
 * the same mechanism Nextcloud emits for a user-pinned theme, so pixels are
 * identical but no occ writes hit the shared instance (tc/mc/ic convention).
 */
async function applyTheme(page, theme) {
	await page.evaluate((id) => {
		const body = document.body;
		['theme--light', 'theme--dark', 'theme--dark-highcontrast', 'theme--light-highcontrast']
			.forEach((c) => body.classList.remove(c));
		['data-theme-default', 'data-theme-light', 'data-theme-dark', 'data-theme-dark-highcontrast', 'data-theme-light-highcontrast']
			.forEach((a) => body.removeAttribute(a));
		if (id === 'dark') {
			body.classList.add('theme--dark');
			body.setAttribute('data-theme-dark', 'true');
			body.setAttribute('data-themes', 'dark');
		} else {
			body.classList.add('theme--light');
			body.setAttribute('data-theme-light', 'true');
			body.setAttribute('data-themes', 'default');
		}
	}, theme);
}

/** Hide volatile chrome so captures are review-clean, not noise-clean. */
async function settle(page, hash) {
	await page.goto(`/apps/carecheck/${hash}`, { waitUntil: 'domcontentloaded' });
	await page.evaluate(() => {
		document.querySelectorAll('.cck-toast, [role="alert"].cck-toast').forEach((n) => n.remove());
	});
	await page.waitForLoadState('networkidle');
	// two painted frames — the real "rendered" signal, not a blind settle
	await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
}

const STATE_PRODUCERS = {
	// Hash-only SPA navigation keeps DOM state — 'populated' cells must undo
	// whatever an earlier 'empty' pass left behind (2099 board date, filters).
	populated: {
		board: async (page) => {
			const date = page.locator('[data-cck-board-date]');
			await date.fill(TODAY);
			await date.dispatchEvent('change');
			await expect(page.locator('.cck-stop').first()).toBeVisible({ timeout: 15_000 });
		},
		clients: async (page) => {
			await page.locator('[data-cck-clients-filter] [name="q"]').fill('');
			// bindListFilter debounces text input 200ms — wait for a real row
			// to repopulate past the debounce before the shutter fires.
			await expect(page.locator('[data-cck-clients] tbody tr:not(:has(td.cck-empty))').first()).toBeVisible({ timeout: 10_000 });
		},
		members: async (page) => {
			await page.locator('[data-cck-members-filter] [name="q"]').fill('');
			await expect(page.locator('[data-cck-members] tbody tr:not(:has(td.cck-empty))').first()).toBeVisible({ timeout: 10_000 });
		},
		catalog: async (page) => {
			await page.locator('[data-cck-catalog-filter] [name="q"]').fill('');
			await expect(page.locator('[data-cck-catalog] tbody tr:not(:has(td.cck-empty))').first()).toBeVisible({ timeout: 10_000 });
		},
	},
	empty: {
		board: async (page) => {
			const date = page.locator('[data-cck-board-date]');
			await date.fill(EMPTY_DATE);
			await date.dispatchEvent('change');
			// A date with no tours renders the honest empty/setup state.
			await expect(page.locator('[data-cck-setup]')).toBeVisible({ timeout: 15_000 });
		},
		clients: async (page) => {
			await page.locator('[data-cck-clients-filter] [name="q"]').fill(NO_MATCH);
			await expect(page.locator('[data-cck-clients] .cck-empty')).toBeVisible({ timeout: 10_000 });
		},
		members: async (page) => {
			await page.locator('[data-cck-members-filter] [name="q"]').fill(NO_MATCH);
			await expect(page.locator('[data-cck-members] .cck-empty')).toBeVisible({ timeout: 10_000 });
		},
		catalog: async (page) => {
			await page.locator('[data-cck-catalog-filter] [name="q"]').fill(NO_MATCH);
			await expect(page.locator('[data-cck-catalog] .cck-empty')).toBeVisible({ timeout: 10_000 });
		},
	},
};

function shotName(surface, state, vp, theme) {
	return `cck-${surface.id}-${state}-${vp.name}${theme === 'dark' ? '-dark' : ''}.png`;
}

function* matrixCells(theme) {
	for (const surface of MANIFEST.surfaces) {
		for (const state of surface.states) {
			for (const vp of MANIFEST.viewports) {
				if (theme === 'dark' && (!surface.dark || vp.name !== 'desktop' || state !== surface.states[0])) { continue; }
				yield { surface, state, vp };
			}
		}
	}
}

test.describe('SCREENSHOT_MATRIX capture', () => {
	test.setTimeout(300_000);

	test.beforeAll(async ({ browser }) => {
		const ctx = await browser.newContext();
		const page = await ctx.newPage();
		await login(page, CREDS);
		const request = page.context().request;
		const html = await (await request.get('/apps/carecheck/')).text();
		rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
		expect(rt).not.toBe('');

		const weekday = ((new Date().getDay() + 6) % 7) + 1;
		const m = await api(request, '/api/members', { method: 'POST', data: { display_name: `MX Pfleger ${TS}`, qualification: 'fachkraft' } });
		expect(m.success).toBe(true);
		const c = await api(request, '/api/clients', { method: 'POST', data: { name: `MX Klient ${TS}`, street: 'Matrixweg 1' } });
		expect(c.success).toBe(true);
		clientId = c.data.id;
		await api(request, `/api/clients/${clientId}/journal`, { method: 'POST', data: { text: `Matrix note ${TS}` } });
		await api(request, '/api/catalog', { method: 'POST', data: { code: `mx-${TS}`, label: `MX Task ${TS}`, requires_qual: null } });
		const tpl = await api(request, '/api/tour-templates', {
			method: 'POST',
			data: {
				name: `MX Tour ${TS}`, weekday_set: [weekday], status: 'published',
				stops: [{ client_id: clientId, win_start: 480, win_end: 600, order_hint: 0, executor_ref: `member:${m.data.id}`, kind: 'fixed' }],
			},
		});
		expect(tpl.success).toBe(true);
		await api(request, '/api/tours/generate', { method: 'POST' });
		await ctx.close();
	});

	test('captures every manifest cell', async ({ page }) => {
		await login(page, CREDS);
		mkdirSync(OUT, { recursive: true });
		const missing = [];

		for (const theme of ['light', 'dark']) {
			for (const { surface, state, vp } of matrixCells(theme)) {
				await page.setViewportSize({ width: vp.width, height: vp.height });
				const hash = surface.hash.replace('{clientId}', String(clientId));
				await settle(page, hash);
				await applyTheme(page, theme);
				await expect(page.locator(`[data-cck-view="${surface.id}"]`)).toBeVisible({ timeout: 15_000 });
				const produce = (STATE_PRODUCERS[state] || {})[surface.id];
				if (state === 'empty') {
					expect(produce, `no empty-state producer for ${surface.id}`).toBeTruthy();
				}
				if (produce) { await produce(page); }
				const file = join(OUT, shotName(surface, state, vp, theme));
				// #app-content scrolls internally — a viewport-height screenshot
				// would only capture the fold. Grow the viewport to the scroller's
				// full content height (capped for pathological lists).
				const contentH = await page.evaluate(() =>
					(document.querySelector('#app-content') || document.documentElement).scrollHeight);
				const shotH = Math.min(Math.max(contentH + 60, vp.height), 8192);
				if (shotH !== vp.height) {
					await page.setViewportSize({ width: vp.width, height: shotH });
					await page.evaluate(() => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r))));
				}
				await page.screenshot({ path: file });
				if (!existsSync(file)) { missing.push(file); }
			}
		}

		expect(missing, `matrix cells missing: ${missing.join(', ')}`).toEqual([]);
	});
});
