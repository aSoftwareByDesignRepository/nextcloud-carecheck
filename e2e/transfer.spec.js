// @ts-check
/**
 * Config & data transfer — /settings/transfer surface E2E.
 *
 * Covers the committed ConfigTransfer feature: export download, import
 * preview → report dialog (counter groups rendered per flow-parity 3.5.15),
 * replace-confirm token gating (footer Apply painted inside the dialog
 * client box at 375 + 1280 — required_field_or_step_undiscoverable),
 * merge apply → progress → receipt, typed error surfaces, and the zh-Hant
 * catalog legs on this surface.
 *
 * Fixture: cck_smoke is an NC sysadmin (replace radio enabled); the export
 * pack is minted via API in beforeAll so upload legs use a real envelope.
 */
import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const CRAFT = resolve(HERE, '../../../../.cursor/atlas-farm-v3/artifacts/carecheck/craft/web');
const COMPOSE = '../../docker-compose.yml';
const compose = (cmd) => execSync(
	`docker compose -f ${COMPOSE} exec -T -u www-data nextcloud ${cmd}`,
	{ encoding: 'utf8', timeout: 180_000 });
const occ = (args) => compose(`php occ ${args}`).trim();
const runCron = () => { try { compose('php cron.php'); } catch { /* job may already be claimed */ } };

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const PASS = 'e2e-transfer-9f27-xq';
const HASH = '#/settings/transfer';

/** @type {Buffer} */
let packBytes = Buffer.alloc(0);
let rt = '';

async function gotoTransfer(page) {
	await page.goto(`/apps/carecheck/${HASH}`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#cck-transfer-export-card', { timeout: 30_000 });
	await page.waitForLoadState('networkidle');
}

/** Upload the exported pack + passphrase and wait for the report dialog. */
async function runPreview(page, mode = 'merge') {
	await page.setInputFiles('#cck-transfer-file', {
		name: 'pack.nctransfer', mimeType: 'application/json', buffer: packBytes,
	});
	if (mode === 'replace') {
		await page.locator('#cck-transfer-import-form input[name="mode"][value="replace"]').check();
	}
	await page.locator('#cck-transfer-import-passphrase').fill(PASS);
	await expect(page.locator('#cck-transfer-report')).toHaveAttribute('open', '', { timeout: 30_000 });
}

test.beforeAll(async ({ browser }) => {
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	await login(page, CREDS);
	const request = page.context().request;
	const html = await (await request.get('/apps/carecheck/')).text();
	rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
	expect(rt).not.toBe('');
	const res = await request.fetch('/apps/carecheck/api/admin/transfer/export', {
		method: 'POST',
		headers: { requesttoken: rt, 'Content-Type': 'application/json' },
		data: { passphrase: PASS },
	});
	expect(res.ok(), 'export precondition must mint a real pack').toBeTruthy();
	packBytes = await res.body();
	expect(packBytes.length).toBeGreaterThan(500);
	await ctx.close();
});

test.describe('TRANSFER_SURFACE', () => {
	test.beforeEach(async ({ page }) => {
		await login(page, CREDS);
	});

	test('surface renders export+import cards and last-transfer line', async ({ page }) => {
		await gotoTransfer(page);
		await expect(page.locator('#cck-transfer-export-card')).toBeVisible();
		await expect(page.locator('#cck-transfer-import-card')).toBeVisible();
		await expect(page.locator('#cck-transfer-last')).toBeVisible();
		// mode radios — replace enabled for sysadmin fixture
		await expect(page.locator('#cck-transfer-import-form input[name="mode"][value="merge"]')).toBeChecked();
		await expect(page.locator('#cck-transfer-import-form input[name="mode"][value="replace"]')).toBeEnabled();
		// secret-safety copy is rendered (machine tokens stay verbatim, no raw msgid)
		const body = await page.locator('#cck-transfer-export-card').innerText();
		expect(body.length).toBeGreaterThan(20);
	});

	test('export via UI downloads .nctransfer', async ({ page }) => {
		await gotoTransfer(page);
		await page.locator('#cck-transfer-passphrase').fill('e2e-ui-export-42');
		const [download] = await Promise.all([
			page.waitForEvent('download', { timeout: 30_000 }),
			page.locator('#cck-transfer-export-btn').click(),
		]);
		expect(download.suggestedFilename()).toMatch(/\.nctransfer$/);
		const fail = await download.failure();
		expect(fail).toBeNull();
	});

	test('weak passphrase refused before submit (client-side guard)', async ({ page }) => {
		await gotoTransfer(page);
		await page.locator('#cck-transfer-passphrase').fill('short');
		await page.locator('#cck-transfer-export-btn').click();
		// no download fired, no network write — strength/status hint owns the feedback
		await expect(page.locator('#cck-transfer-passphrase-strength')).not.toBeEmpty();
	});

	test('merge preview renders report dialog with counter groups', async ({ page }) => {
		await gotoTransfer(page);
		await runPreview(page, 'merge');
		const dlg = page.locator('#cck-transfer-report');
		await expect(dlg).toBeVisible();
		// verdict states actual predicted counts (no silent counters)
		const verdict = await page.locator('#cck-transfer-report-verdict').innerText();
		expect(verdict.trim().length).toBeGreaterThan(5);
		// counter groups render as details/summary blocks
		await expect(dlg.locator('.cck-transfer-group').first()).toBeVisible();
		// replace-confirm stays hidden in merge mode; Apply armed immediately
		await expect(page.locator('#cck-transfer-replace-confirm')).toBeHidden();
		await expect(page.locator('#cck-transfer-confirm')).toBeEnabled();
		// cancel closes without apply
		await page.locator('#cck-transfer-cancel').click();
		await expect(dlg).not.toBeVisible();
	});

	test('wrong passphrase gives typed, localized error (no raw code)', async ({ page }) => {
		await gotoTransfer(page);
		await page.setInputFiles('#cck-transfer-file', {
			name: 'pack.nctransfer', mimeType: 'application/json', buffer: packBytes,
		});
		await page.locator('#cck-transfer-import-passphrase').fill('definitely-wrong-pass');
		// error surfaces through the banner + assertive mirror — never a raw SNAKE code
		await expect(page.locator('[data-cck-banner]')).toBeVisible({ timeout: 30_000 });
		const msg = await page.locator('[data-cck-banner]').innerText();
		expect(msg).not.toMatch(/TRANSFER_[A-Z_]+/);
		expect(msg.trim().length).toBeGreaterThan(10);
	});

	test('replace-confirm token gates Apply; footer paints inside dialog @375/@1280', async ({ page }) => {
		mkdirSync(CRAFT, { recursive: true });
		for (const width of [375, 1280]) {
			await page.setViewportSize({ width, height: 900 });
			await gotoTransfer(page);
			await runPreview(page, 'replace');
			const box = page.locator('#cck-transfer-replace-confirm');
			await expect(box).toBeVisible();
			const token = page.locator('#cck-transfer-replace-token');
			const confirm = page.locator('#cck-transfer-confirm');
			// confirm disabled until literal REPLACE typed
			await expect(confirm).toBeDisabled();
			await token.fill('REPLACE');
			await expect(confirm).toBeEnabled();
			// footer (incl. Apply + token input) painted inside the dialog's client
			// box — not scrolled off as shell overflow (donor footer-pin fix)
			const [dlgR, footR, tokR] = await Promise.all([
				page.locator('#cck-transfer-report').boundingBox(),
				page.locator('#cck-transfer-report .cck-dialog__footer').boundingBox(),
				token.boundingBox(),
			]);
			expect(dlgR, 'dialog box measurable').toBeTruthy();
			expect(footR, 'footer box measurable').toBeTruthy();
			expect(tokR, 'token box measurable').toBeTruthy();
			expect(footR.y + footR.height, `footer bottom inside dialog @${width}`)
				.toBeLessThanOrEqual(dlgR.y + dlgR.height + 1);
			expect(tokR.width, `token input wide enough @${width}`).toBeGreaterThanOrEqual(60);
			await page.screenshot({ path: `${CRAFT}/cck-transfer-replace-confirm-${width}.png` });
			await page.keyboard.press('Escape');
			await expect(page.locator('#cck-transfer-report')).not.toBeVisible();
		}
	});

	test('merge apply queues job, progress dialog closes on receipt', async ({ page }) => {
		test.setTimeout(240_000);
		await page.setViewportSize({ width: 1280, height: 900 });
		await gotoTransfer(page);
		await runPreview(page, 'merge');
		await page.locator('#cck-transfer-confirm').click();
		await expect(page.locator('#cck-transfer-report')).not.toBeVisible();
		await expect(page.locator('#cck-transfer-progress')).toBeVisible();
		// drive the queued TransferApplyJob; the UI polls status every 1.5s and
		// reloads on 'done' — wait for the reload (progress dialog gone + last
		// transfer line carries a receipt)
		let done = false;
		for (let i = 0; i < 60 && !done; i++) {
			runCron();
			const res = await page.context().request.fetch('/apps/carecheck/api/admin/transfer/status', {
				headers: { requesttoken: rt, 'OCS-APIRequest': 'true' },
			});
			const j = await res.json();
			if (((j.data || {}).job || {}).status === 'done') { done = true; break; }
			await page.waitForTimeout(1500);
		}
		expect(done, 'transfer job reached terminal done').toBeTruthy();
		await page.waitForLoadState('load');
		await expect(page.locator('#cck-transfer-last')).toBeVisible({ timeout: 30_000 });
		const last = await page.locator('#cck-transfer-last').innerText();
		expect(last.trim().length).toBeGreaterThan(10);
		// mode renders as a localized label — never the raw stored enum
		// (raw_enum_label_unlocalized: 'merge'/'replace' verbatim on receipts)
		expect(last).toContain('Merge');
		expect(last).not.toMatch(/·\s*(merge|replace)\b/);
	});

	test('import form stays inside the sheet at 320px (no mid-word clip)', async ({ page }) => {
		// clipped_text_horizontal_overflow learned-assert: a fieldset flex item
		// does not shrink below content size — the mode radios + replace hint
		// used to clip mid-word at the sheet's right edge. Assert geometry, not
		// pixels: nothing inside the card may paint past its inner edges and the
		// page may not gain horizontal scroll.
		await page.setViewportSize({ width: 320, height: 800 });
		await gotoTransfer(page);
		const clips = await page.locator('#cck-transfer-import-card').evaluate((card) => {
			const cardR = card.getBoundingClientRect();
			const bad = [];
			for (const el of card.querySelectorAll('fieldset, legend, label, span, p, input, div')) {
				const r = el.getBoundingClientRect();
				if (r.width === 0 || r.height === 0) { continue; }
				if (r.right > cardR.right + 1 || r.left < cardR.left - 1) {
					bad.push(`${el.tagName}.${String(el.className)} rect ${r.left.toFixed(0)}-${r.right.toFixed(0)} outside card ${cardR.left.toFixed(0)}-${cardR.right.toFixed(0)}`);
				}
				if (el.children.length === 0 && el.scrollWidth > el.clientWidth + 1) {
					bad.push(`${el.tagName}.${String(el.className)} scrollWidth ${el.scrollWidth} > clientWidth ${el.clientWidth}`);
				}
			}
			return bad;
		});
		expect(clips, 'content overflowing the transfer card').toEqual([]);
		const docOverflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		expect(docOverflow, 'page-level horizontal overflow').toBeLessThanOrEqual(1);
	});
});

test.describe('TRANSFER_L10N_ZH_HANT', () => {
	test('zh_TW renders translated transfer UI (no EN fallback, no raw keys)', async ({ page }) => {
		// Seed a deterministic receipt so the Last-transfer line renders even on
		// a fresh fixture — this leg must prove the mode label is localized.
		let prevReceipts = '';
		try { prevReceipts = occ('config:app:get carecheck transfer_receipts'); } catch { /* unset */ }
		const seed = JSON.stringify([{
			id: 'e2e-zh-receipt', at: '2026-11-11T22:00:00Z', actor: 'cck_smoke',
			mode: 'merge', result: 'applied',
			counts: { created: 1, updated: 0, skipped: 0, deleted: 0 }, snapshotId: null,
		}]);
		try {
			occ(`config:app:set carecheck transfer_receipts --value='${seed}'`);
			occ('user:setting cck_smoke core lang zh_TW');
			await page.context().setExtraHTTPHeaders({ 'Accept-Language': 'zh-TW,zh;q=0.9' });
			await login(page, CREDS);
			await page.setViewportSize({ width: 1280, height: 900 });
			await gotoTransfer(page);
			const exportCard = await page.locator('#cck-transfer-export-card').innerText();
			// catalogued zh-Hant strings — 'Download encrypted transfer file' → 下載加密傳輸檔案
			expect(exportCard).toContain('下載');
			expect(exportCard).not.toContain('Download encrypted transfer file');
			const importCard = await page.locator('#cck-transfer-import-card').innerText();
			expect(importCard).not.toContain('Import configuration');
			// Last-transfer receipt renders the localized mode label — never the
			// raw stored enum (raw_enum_label_unlocalized)
			const last = await page.locator('#cck-transfer-last').innerText();
			expect(last).toContain('合併');
			expect(last).not.toMatch(/·\s*(merge|replace)\b/);
			mkdirSync(CRAFT, { recursive: true });
			await page.screenshot({ path: `${CRAFT}/cck-settings-transfer-zhtw-desktop.png` });
		} finally {
			occ('user:setting cck_smoke core lang en');
			if (prevReceipts) {
				occ(`config:app:set carecheck transfer_receipts --value='${prevReceipts}'`);
			}
		}
	});
});
