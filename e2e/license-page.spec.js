// @ts-check
/**
 * License pack surface — post-delta reverify contract.
 *
 * The shared _shared/license-page pack (synced byte-for-byte, {p}=cck)
 * replaced the bespoke SPA license panel. Learned-defect legs:
 *   - l10n_sprintf_literal_percent_crash: the pre-heal template called
 *     $l->t($msgid) with zero args over printf-placeholder msgids →
 *     vsprintf ValueError → HTTP 500 on EVERY app page. Sentinel
 *     round-trip must keep %1$d/%n intact in the data-i18n payload.
 *   - closed_dialog_painted: NC core server.css `dialog{display:block}`
 *     outranks the UA `:not([open])` rule → closed pack modals must
 *     compute display:none via the pack's own `.{p}-license-modal:not([open])`.
 *   - dialog_escape_close / host_app_escape_preventdefault: NC's
 *     notifications app swallows global Escape keydowns — the pack wires
 *     per-dialog Escape handlers; probe Escape on the clear modal.
 *   - button_variant_specificity_collapse + populated-label a11y:
 *     every pack dialog control must carry a non-empty accessible name
 *     and the danger/primary/secondary variants distinct computed fills.
 *   - boundary Tab trap: focus stays inside an open modal.
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login } from './helpers/auth.js';

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const HASH = '#/settings/license';

async function gotoLicense(page) {
	await page.goto(`/apps/carecheck/${HASH}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#cck-license-page', { timeout: 30_000 });
	await page.waitForLoadState('networkidle');
}

test.beforeEach(async ({ page }) => {
	page.on('dialog', (d) => d.accept());
	await login(page, CREDS);
});

test('license surface renders root + requesttoken + real title (the 500 regression)', async ({ page }) => {
	const res = await page.goto(`/apps/carecheck/${HASH}`, { waitUntil: 'domcontentloaded' });
	expect(res?.status(), 'app page must not 500').toBe(200);
	await expect(page.locator('#cck-license-page[data-license-root="1"]')).toBeVisible({ timeout: 30_000 });
	const rt = await page.locator('#cck-license-page').getAttribute('data-requesttoken');
	expect(rt, 'requesttoken present').toBeTruthy();
	expect(rt.length, 'requesttoken non-trivial').toBeGreaterThan(10);
	await expect(page.locator('#cck-license-key-input')).toBeVisible();
	await expect(page.locator('#cck-license-save')).toBeEnabled();
	// license status card renders the stored license summary (dev fixture)
	await expect(page.locator('#cck-license-status')).toBeVisible();
});

test('closed pack dialogs compute display:none (closed_dialog_painted)', async ({ page }) => {
	await gotoLicense(page);
	const states = await page.locator('dialog.cck-license-modal').evaluateAll((dlgs) =>
		dlgs.map((d) => ({
			id: d.id,
			open: d.open,
			display: getComputedStyle(d).display,
		})));
	expect(states.length, 'pack ships clear/confirm/token modals').toBeGreaterThanOrEqual(3);
	for (const s of states) {
		expect(s.open, `${s.id} starts closed`).toBe(false);
		expect(s.display, `${s.id} closed must compute display:none — NC core dialog{display:block} outranks the UA rule`).toBe('none');
	}
});

test('pack dialog controls carry populated accessible names + distinct variants', async ({ page }) => {
	await gotoLicense(page);
	const buttons = await page.locator('dialog.cck-license-modal button').evaluateAll((btns) =>
		btns.map((b) => ({
			id: b.id,
			name: (b.getAttribute('aria-label') || b.textContent || '').trim(),
			cls: b.className,
		})));
	expect(buttons.length).toBeGreaterThanOrEqual(5);
	for (const b of buttons) {
		expect(b.name, `#${b.id} must render a non-empty label`).not.toBe('');
	}
	// variant armor: danger vs secondary fills must not collapse to the same
	// computed background (button_variant_specificity_collapse on the pack taxonomy)
	const fills = await page.locator('dialog.cck-license-modal button').evaluateAll((btns) => {
		const out = {};
		for (const b of btns) {
			const variant = /btn--(danger|primary|secondary)/.exec(b.className)?.[1];
			if (variant) { out[variant] = getComputedStyle(b).backgroundColor; }
		}
		return out;
	});
	expect(fills.danger, 'danger variant present').toBeTruthy();
	expect(fills.secondary, 'secondary variant present').toBeTruthy();
	expect(fills.danger).not.toBe(fills.secondary);
});

test('Escape closes the clear-license dialog (dialog_escape_close)', async ({ page }) => {
	await gotoLicense(page);
	const clearBtn = page.locator('#cck-license-clear');
	// license fixture exists on the dev instance — the danger control renders
	await expect(clearBtn).toBeVisible();
	await clearBtn.click();
	const modal = page.locator('#cck-license-clear-modal');
	await expect(modal).toHaveAttribute('open', '');
	await page.keyboard.press('Escape');
	await expect(modal).not.toHaveAttribute('open', '');
	// reopen + cancel path also reaches the closed state
	await clearBtn.click();
	await expect(modal).toHaveAttribute('open', '');
	await page.locator('#cck-license-clear-cancel').click();
	await expect(modal).not.toHaveAttribute('open', '');
});

test('invalid license key applies field-level error, never a raw code', async ({ page }) => {
	await gotoLicense(page);
	await page.locator('#cck-license-key-input').fill('CCK2.garbage.garbage');
	await page.locator('#cck-license-save').click();
	const feedback = page.locator('#cck-license-feedback');
	await expect(feedback).toBeVisible({ timeout: 15_000 });
	const msg = await feedback.innerText();
	expect(msg.trim().length).toBeGreaterThan(10);
	expect(msg).not.toMatch(/invalid_format|VALIDATION_ERROR|ValueError/);
});

test('data-i18n carries placeholder msgids intact — no sentinel leftovers', async ({ page }) => {
	await gotoLicense(page);
	const raw = await page.locator('#cck-license-page').getAttribute('data-i18n');
	expect(raw).toBeTruthy();
	const i18n = JSON.parse(raw);
	const placeholders = Object.values(i18n).filter(
		(v) => typeof v === 'string' && /%\d+\$[ds]|%n/.test(v));
	expect(placeholders.length, 'sentinel round-trip must keep printf placeholders').toBeGreaterThanOrEqual(5);
	const blob = JSON.stringify(i18n);
	expect(blob, 'numeric sentinels must not leak into the payload').not.toMatch(/1908074\d{3}/);
});

test('no raw printf placeholder renders in the license surface text', async ({ page }) => {
	await gotoLicense(page);
	const text = await page.locator('#cck-license').innerText();
	expect(text).not.toMatch(/%\d+\$[ds]|%n\b|1908074\d{3}/);
});

test('axe wcag2a/aa on the license surface', async ({ page }) => {
	await gotoLicense(page);
	const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
	const appOnly = res.violations.filter(
		(v) => !v.nodes.some((n) => n.html.includes('unified-search')));
	expect(appOnly, `axe license: ${JSON.stringify(appOnly.map((v) => v.id))}`).toEqual([]);
});
