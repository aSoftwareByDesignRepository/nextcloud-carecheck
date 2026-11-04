// @ts-check
/**
 * Rendered regression proof for the 2026-11-04 farm class-gate sweep
 * (required_field_or_step_undiscoverable / check-select-background-shorthand.py):
 * the `#app-content .cck-team-sel` rule in css/app.css painted the bare
 * `background:` shorthand, resetting the NC core picker caret
 * (image/position/repeat/size) — member-row team selects read as plain text
 * fields in light and a tiled caret grid in dark.
 *
 * Legs (computed style, light AND dark):
 *  1. Member-row team select `select.cck-team-sel` — the rule the sweep
 *     flagged; needs one member row (seeded in beforeAll).
 *  2. Members filter select `#cck-mf-qual` — statically rendered on the same
 *     view, guards the rest of the select family.
 *
 * Theme flips client-side (theme--* / data-theme-*), the same mechanism
 * capture-screenshot-matrix uses — no occ writes on the shared instance.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
const TS = Date.now()
const MEMBER_NAME = `Chevron Pfleger ${TS}`
const THEMES = ['light', 'dark']

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

/** Computed chevron probe: core caret = url() image + no-repeat + positioned. */
async function probeChevron(select, theme, label) {
	const probe = await select.evaluate((node) => {
		const cs = getComputedStyle(node)
		return {
			image: cs.backgroundImage,
			repeat: cs.backgroundRepeat,
			position: cs.backgroundPosition,
		}
	})
	expect(probe.image, `${theme} ${label}: chevron background-image missing (bare background: regression)`).toContain('url(')
	expect(probe.repeat, `${theme} ${label}: chevron tiles (dark-grid regression)`).toBe('no-repeat')
	expect(probe.position, `${theme} ${label}: chevron unpositioned`).not.toBe('0% 0%')
}

test.beforeAll(async ({ browser }) => {
	const ctx = await browser.newContext()
	const page = await ctx.newPage()
	await login(page, CREDS)
	const request = page.context().request
	const html = await (await request.get('/apps/carecheck/')).text()
	const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
	expect(rt).not.toBe('')
	const res = await request.fetch('/apps/carecheck/api/members', {
		method: 'POST',
		headers: { requesttoken: rt, 'Content-Type': 'application/json' },
		data: { display_name: MEMBER_NAME, qualification: 'fachkraft' },
	})
	const body = await res.json()
	expect(body.success, JSON.stringify(body)).toBe(true)
	await ctx.close()
})

test.beforeEach(async ({ page }) => {
	await login(page, CREDS)
})

test.describe('select chevron contract', () => {
	test('member-row team select keeps the picker chevron (light+dark)', async ({ page }) => {
		await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
		const select = page.locator('[data-cck-members] tr', { hasText: MEMBER_NAME }).locator('select.cck-team-sel')
		await expect(select).toHaveCount(1)
		for (const theme of THEMES) {
			await applyTheme(page, theme)
			await probeChevron(select, theme, 'cck-team-sel')
		}
	})

	test('members filter select keeps the picker chevron (light+dark)', async ({ page }) => {
		await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
		const select = page.locator('#cck-mf-qual')
		await expect(select).toHaveCount(1)
		for (const theme of THEMES) {
			await applyTheme(page, theme)
			await probeChevron(select, theme, 'cck-mf-qual')
		}
	})
})
