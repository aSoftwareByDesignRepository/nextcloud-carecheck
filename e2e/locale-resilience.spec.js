/**
 * ATLAS Lens 5 — locale resilience: real long-string locale (de), not lorem.
 * German copy must render, and layout must survive ~30–50% longer strings
 * at the mobile boundary without pan or clipped controls.
 */
import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';

const COMPOSE = '../../docker-compose.yml';
const occ = (cmd) => execSync(
	`docker compose -f ${COMPOSE} exec -T -u www-data nextcloud php occ ${cmd}`,
	{ encoding: 'utf8' }).trim();

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };

test.describe('LOCALE_RESILIENCE', () => {
	test('de locale: real text rendered, no pan, no clipped controls @320/768', async ({ page }) => {
		// occ config:system:get exits non-zero when the key is unset — that is
		// "not forced", not an error. Tolerate it here only.
		let forcedLanguage = '';
		try {
			forcedLanguage = occ('config:system:get force_language');
		} catch { /* unset */ }
		test.skip(forcedLanguage !== '' && !forcedLanguage.startsWith('de'), `Nextcloud force_language=${forcedLanguage}`);
		occ('user:setting cck_smoke core lang de');
		try {
			await page.context().setExtraHTTPHeaders({ 'Accept-Language': 'de-DE,de;q=0.9' });
			await login(page, CREDS);
			for (const width of [320, 768]) {
				await page.setViewportSize({ width, height: 900 });
				for (const hash of ['#/board', '#/clients', '#/inbox']) {
					await page.goto(`/apps/carecheck/${hash}`, { waitUntil: 'domcontentloaded' });
					await page.waitForSelector('#app-content .cck-view:not([hidden])', { timeout: 30_000 });
					const pan = await page.evaluate(
						() => document.documentElement.scrollWidth - document.documentElement.clientWidth,
					);
					expect(pan, `horizontal pan at ${width}px on ${hash}`).toBe(0);
					const clipped = await page.evaluate(() =>
						[...document.querySelectorAll('button, a, input, select')]
							.filter((el) => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1)
							.map((el) => `${el.tagName}.${el.className}`.slice(0, 60)),
					);
					expect(clipped, `clipped controls at ${width}px on ${hash}`).toEqual([]);
				}
			}
			// German actually rendered — not a key, not English fallback.
			await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' });
			await page.waitForSelector('#app-content .cck-view:not([hidden])', { timeout: 30_000 });
			const text = await page.locator('#app-content').innerText();
			expect(text).toContain('Tagesübersicht');
			expect(text).not.toContain('Coverage board');
		} finally {
			occ('user:setting cck_smoke core lang en');
		}
	});
});
