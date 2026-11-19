// @ts-check
/**
 * SETTINGS_SUBNAV_CONTRACT — every sidebar subnav link resolves to a
 * mounted settings section (no dead href). Labels are read from the
 * app's own l10n catalog for the fixture user's locale, never hardcoded.
 * Run with CCK_NAV_LOCALE=<locale> matching `occ user:setting cck_smoke core lang`.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { login } from './helpers/auth.js';

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const LOCALE = process.env.CCK_NAV_LOCALE || 'en';
const CATALOG = JSON.parse(readFileSync(new URL(`../l10n/${LOCALE}.json`, import.meta.url), 'utf8'));
const t = (msgid) => (CATALOG.translations?.[msgid] ?? msgid);

const SUBNAV = ['general', 'companion', 'license', 'access', 'privacy', 'transfer', 'support'];
const sideLink = (page, sec) => page.locator(`a.cck-nav__sublink[data-cck-subnav="${sec}"]`);
// Sections rendered lazily from <template data-cck-settings-tpl> get a DOM
// marker once mounted; license/transfer are server-rendered for app admins.
const SECTION_MARKERS = {
	general: '[data-cck-settings-section="general"]',
	companion: '[data-cck-settings-section="companion"]',
	license: '#cck-license',
	access: '[data-cck-settings-section="access"]',
	privacy: '[data-cck-privacy-state]',
	transfer: '[data-cck-settings-section="transfer"]',
	support: '[data-cck-settings-section="support"]',
};

test.beforeEach(async ({ page }, testInfo) => {
	test.skip(testInfo.project.name === 'mobile', 'sidebar subnav is desktop chrome; drawer is covered by atlas-mobile-nav.contract');
	await login(page, CREDS);
	await page.goto('/apps/carecheck/#/settings/general', { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('a.cck-nav__sublink[data-cck-subnav="general"]', { timeout: 30000 });
});

test('settings subnav carries all sections with localized labels', async ({ page }) => {
	for (const sec of SUBNAV) {
		const link = sideLink(page, sec);
		await expect(link, `subnav link ${sec}`).toHaveCount(1);
		await expect(link).toBeVisible();
		await expect(link).toHaveAttribute('href', `#/settings/${sec}`);
		const text = (await link.textContent())?.trim();
		expect(text?.length, `subnav ${sec} label`).toBeGreaterThan(0);
	}
	await expect(sideLink(page, 'license')).toHaveText(t('License'));
	await expect(sideLink(page, 'privacy')).toHaveText(t('Privacy & register'));
});

test('license and privacy subnav links mount their sections', async ({ page }) => {
	for (const sec of ['license', 'privacy']) {
		await sideLink(page, sec).click();
		await expect(page).toHaveURL(new RegExp(`#/settings/${sec}$`));
		const view = page.locator(`[data-cck-view="settings-${sec}"]`);
		await expect(view).toBeVisible();
		const marker = view.locator(SECTION_MARKERS[sec]);
		await expect(marker, `section ${sec} mounted`).toHaveCount(1);
	}
});

test('every subnav target mounts a non-empty section (no dead href)', async ({ page }) => {
	for (const sec of SUBNAV) {
		await sideLink(page, sec).click();
		const view = page.locator(`[data-cck-view="settings-${sec}"]`);
		await expect(view, `view ${sec}`).toBeVisible();
		const marker = view.locator(SECTION_MARKERS[sec]);
		await expect(marker, `section ${sec} marker`).toHaveCount(1);
		expect((await marker.textContent())?.trim().length, `section ${sec} content`).toBeGreaterThan(0);
	}
});
