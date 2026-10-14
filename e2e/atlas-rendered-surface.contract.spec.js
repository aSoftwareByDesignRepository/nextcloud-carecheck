// @ts-check
/**
 * ATLAS_RENDERED_SURFACE_CONTRACT — rendered truth of each CareCheck surface:
 * list markers not reset by shell CSS, selects vertically centred (not sunken),
 * icons non-zero, controls not center-aligned by inherited shell styles.
 * CareCheck is a hash-routed SPA — each route gets its own pass.
 */
import { test } from '@playwright/test';
import shared from '../../_shared/e2e/atlas-rendered-surface-contract.js';
import { login } from './helpers/auth.js';

const { assertAtlasRenderedSurface } = shared;
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };

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
			await assertAtlasRenderedSurface(page, {
				content: '#app-content.cck-app',
				navExclude: '#app-navigation',
				listAllow: LIST_ALLOW,
			});
		});
	}
});
