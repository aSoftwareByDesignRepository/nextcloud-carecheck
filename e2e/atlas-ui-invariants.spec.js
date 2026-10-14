// @ts-check
/**
 * Atlas UI invariants — shared-contract coverage for CareCheck web surfaces.
 * Covered here: a11y-dom (icon-only names, <24px targets, aria-hidden focusables),
 * stored-xss scan on rendered boards, console errors, raw i18n keys.
 * Double-submit/field-error freshness is covered by e2e/journey.spec.js.
 */
import { test } from '@playwright/test';
import shared from '../../_shared/e2e/atlas-ui-invariants.js';
import { login } from './helpers/auth.js';

const {
	assertA11yDom,
	assertNoInjection,
	assertNoConsoleErrors,
	assertNoRawI18nKeys,
	trackConsoleErrors,
} = shared;

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const CONTENT = '#app-content.cck-app';

const SURFACES = [
	['#/board', 'board'],
	['#/clients', 'clients'],
	['#/tours', 'tours'],
	['#/members', 'members'],
	['#/catalog', 'catalog'],
	['#/inbox', 'inbox'],
	['#/settings/general', 'settings general'],
	['#/settings/companion', 'settings companion'],
	['#/getapp', 'get the app'],
];

test.beforeEach(async ({ page }) => {
	await login(page, CREDS);
});

test.describe('ATLAS_UI_INVARIANTS', () => {
	for (const [hash, name] of SURFACES) {
		test(`a11y-dom + console + raw-i18n: ${name}`, async ({ page }) => {
			const consoleErrs = trackConsoleErrors(page);
			await page.setViewportSize({ width: 1280, height: 800 });
			await page.goto(`/apps/carecheck/${hash}`, { waitUntil: 'domcontentloaded' });
			await page.waitForSelector(`${CONTENT} .cck-view:not([hidden])`, { timeout: 30_000 });

			const findings = await assertA11yDom(page, {
				content: CONTENT,
				minTarget: 24,
				sizeAllow: '.cck-weekdays input[type="checkbox"], .cck-plan-task input[type="checkbox"]',
			});
			if (findings.length) {
				throw new Error(`a11y-dom findings on ${name}:\n${findings.join('\n')}`);
			}
			await assertNoInjection(page);
			await assertNoRawI18nKeys(page, { content: CONTENT });
			assertNoConsoleErrors(consoleErrs);
		});
	}
});
