// NC App Store screenshots — 1600x867 viewport shots (website convention).
// Seeded data must already exist (screenshot-matrix run provisions it).
// Run from the app dir: node e2e/store-shots.mjs
import { chromium } from 'playwright'
import { login } from './helpers/auth.js'

const APP = process.env.STORE_APP || 'carecheck'
const BASE = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
const OUT = `screenshots/`
const CREDS = APP === 'carecheck'
	? { username: 'cck_smoke', password: 'CckSmoke!2026' }
	: { username: process.env.BCK_E2E_USER || 'bck_atlas', password: process.env.BCK_E2E_PASS || 'BckAtlas2026!' }

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1600, height: 867 } })
const page = await ctx.newPage()
await login(page, CREDS)
await page.goto(`${BASE}/apps/${APP}/`, { waitUntil: 'domcontentloaded' })
const rt = ((await page.content()).match(/data-requesttoken="([^"]+)"/) || [])[1] || ''

const api = async (p) => (await page.request.fetch(`${BASE}/apps/${APP}${p}`, { headers: { requesttoken: rt } })).json()

// resolve seeded ids
let cid = 0, offId = 0, ordId = 0, slug = ''
if (APP === 'carecheck') {
	const c = await api('/api/clients').catch(() => null)
	cid = c?.data?.clients?.[0]?.id ?? 0
} else {
	const offs = await api('/api/offerings').catch(() => null)
	const list = offs?.data ?? offs ?? []
	offId = list[0]?.id ?? 0; slug = list[0]?.slug ?? ''
	const ords = await api('/api/orders').catch(() => null)
	const ol = ords?.data ?? ords ?? []
	ordId = ol[0]?.id ?? 0
}

const shots = APP === 'carecheck'
	? [['board', '/apps/carecheck/#/board'], ['clients', '/apps/carecheck/#/clients'], ['tours', '/apps/carecheck/#/tours'], ['members', '/apps/carecheck/#/members'], ['catalog', '/apps/carecheck/#/catalog'], ['client', `/apps/carecheck/#/client/${cid}`]]
	: [['overview', '/apps/bookingcheck/overview'], ['offerings', '/apps/bookingcheck/offerings'], ['offering-detail', `/apps/bookingcheck/offerings/${offId}`], ['orders', '/apps/bookingcheck/orders'], ['order-detail', `/apps/bookingcheck/orders/${ordId}`], ['public-offering', `/apps/bookingcheck/pub/o/${slug}`]]

for (const [name, url] of shots) {
	await page.goto(`${BASE}${url}`, { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(2500)
	if (name === 'board') {
		// today's real tours live on the next template day (Thu 2026-11-05)
		const d = page.locator('[data-cck-board-date]')
		if (await d.count()) {
			await d.fill('2026-11-05')
			await d.dispatchEvent('change')
			await page.waitForTimeout(2000)
		}
	}
	await page.screenshot({ path: `${OUT}${APP}-${name}.png` })
	console.log(`${name} <- ${url}`)
}
await browser.close()
