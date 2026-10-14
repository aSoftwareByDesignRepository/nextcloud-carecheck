// @ts-check
/** ATLAS_MOBILE_NAV_CONTRACT — phone Menu → open drawer + no h-scroll */
import { test } from '@playwright/test';
import shared from '../../_shared/e2e/atlas-mobile-nav-contract.js';
import { login } from './helpers/auth.js';

const { assertAtlasMobileNav } = shared;
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };

test('ATLAS_MOBILE_NAV_CONTRACT in-page Menu opens drawer', async ({ page }) => {
	await page.setViewportSize({ width: 375, height: 812 });
	await login(page, CREDS);
	await page.goto('/apps/carecheck/', { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('[data-cck-nav-toggle]', { timeout: 30000 });
	await assertAtlasMobileNav(page, {
		toggle: page.locator('[data-cck-nav-toggle]').first(),
		nav: page.locator('#app-navigation'),
		openClass: /cck-nav--open/,
	});
});
