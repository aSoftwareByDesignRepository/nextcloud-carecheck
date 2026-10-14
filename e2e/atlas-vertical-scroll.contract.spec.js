// @ts-check
/**
 * ATLAS_VERTICAL_SCROLL_CONTRACT — the tall settings page must scroll to its
 * Save control. Guards unpaired overflow-x:clip / overflow hidden truncation.
 * CareCheck's tallest surface is /settings/general (presets + policy fields).
 */
import { test } from '@playwright/test';
import shared from '../../_shared/e2e/atlas-vertical-scroll-contract.js';
import { login } from './helpers/auth.js';

const { assertAtlasVerticalScrollReachable } = shared;
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };

test('ATLAS_VERTICAL_SCROLL_CONTRACT settings general scrolls to Save', async ({ page }) => {
	await page.setViewportSize({ width: 1280, height: 640 });
	await login(page, CREDS);
	await page.goto('/apps/carecheck/#/settings/general', { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('[data-cck-settings-form="general"] [type="submit"]', { timeout: 45_000 });

	await assertAtlasVerticalScrollReachable(page, {
		scrollport: '#app-content',
		target: '[data-cck-settings-form="general"] [type="submit"]',
	});
});
