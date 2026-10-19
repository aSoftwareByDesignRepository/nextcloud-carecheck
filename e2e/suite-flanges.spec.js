// @ts-check
import { test, expect } from '@playwright/test'
import { execSync } from 'node:child_process'
import { login } from './helpers/auth.js'

/**
 * Suite-flange surfaces (F12–F15): DOM + wire contract for the cross-app
 * integrations. Nothing is mocked — every leg drives the real sibling.
 *   F12 board duty strip    <- DutyCheck on-duty feed (advisory)
 *   F14 client CRM link     -> CustomerCheck company picker + soft link
 *   F13 invoice-draft push  -> InvoiceCheck draft facade (exports panel)
 *   F15 privacy proposal    -> PrivacyCheck VVT draft entry
 * Seeds: member(+device) → client → plan → template → generate → checkin
 * + submit (done visit this period) → CUC company → pc customer link.
 * Cleanup in afterAll removes every sibling row it minted.
 */
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
const TS = Date.now()
const COMPOSE = '../../docker-compose.yml'
const db = (sql) => execSync(
	`docker compose -f ${COMPOSE} exec -T mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
	{ encoding: 'utf8' },
).trim()

let rt = ''
let deviceToken = ''
let stopId = 0
let clientId = 0
let companyId = 0
let pcCustomerId = 0
let invoiceId = 0

async function api(request, path, opts = {}) {
	const res = await request.fetch(`/apps/carecheck${path}`, {
		...opts,
		headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
	})
	return res.json()
}

// sibling calls share the session — requesttoken is instance-wide
async function sapi(request, app, path, opts = {}) {
	const res = await request.fetch(`/apps/${app}${path}`, {
		...opts,
		headers: {
			requesttoken: rt, 'Content-Type': 'application/json',
			'OCS-APIRequest': 'true', ...(opts.headers || {}),
		},
	})
	return res.json()
}

async function papi(page, path, opts = {}) {
	const html = await (await page.request.get('/apps/carecheck/')).text()
	const token = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
	return api(page.request, path, { ...opts, headers: { requesttoken: token } })
}

const today = () => new Date().toISOString().slice(0, 10)

test.beforeAll(async ({ browser }, testInfo) => {
	// pair redemption may wait out a 10-minute rate-limit window
	testInfo.setTimeout(13 * 60 * 1000)
	const ctx = await browser.newContext()
	const page = await ctx.newPage()
	await login(page, CREDS)
	const request = page.context().request
	const html = await (await request.get('/apps/carecheck/')).text()
	rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
	expect(rt).not.toBe('')

	const m = await api(request, '/api/members', { method: 'POST', data: { display_name: `Flange Pfleger ${TS}`, qualification: 'fachkraft' } })
	expect(m.success).toBe(true)
	await api(request, '/api/settings', { method: 'POST', data: { seat_limit: '999' } })
	const code = (await api(request, `/api/members/${m.data.id}/pair-code`, { method: 'POST' })).data.code
	let pair = { success: false }
	for (let attempt = 0; attempt < 25 && !pair.success; attempt++) {
		pair = await request.fetch('/apps/carecheck/api/v1/pair', {
			method: 'POST', headers: { 'Content-Type': 'application/json' },
			data: { code, device_name: `flange-${TS}`, companion_version: '0.1.0' },
		}).then((r) => r.json())
		if (pair.success || pair.error !== 'RATE_LIMITED') { break }
		await new Promise((res) => setTimeout(res, 30000))
	}
	expect(pair.success, JSON.stringify(pair)).toBe(true)
	deviceToken = pair.data.deviceToken

	const c = await api(request, '/api/clients', { method: 'POST', data: { name: `Flange Klient ${TS}`, street: 'F Weg 1' } })
	expect(c.success).toBe(true)
	clientId = c.data.id
	await api(request, `/api/clients/${clientId}/plan/publish`, {
		method: 'POST', data: { base_revision: 1, tasks: [{ task_code: 'meds', label: 'Medikamente', value_required: false }] },
	})

	const weekday = ((new Date().getDay() + 6) % 7) + 1
	const tpl = await api(request, '/api/tour-templates', {
		method: 'POST',
		data: {
			name: `Flange Tour ${TS}`, weekday_set: [weekday], status: 'published',
			stops: [{ client_id: clientId, win_start: 360, win_end: 720, order_hint: 0, executor_ref: `member:${m.data.id}`, kind: 'fixed' }],
		},
	})
	expect(tpl.success).toBe(true)
	await api(request, '/api/tours/generate', { method: 'POST' })

	const dev = { 'X-CCK-Device-Token': deviceToken, 'Content-Type': 'application/json' }
	const now = new Date()
	for (const d of [now, new Date(now.getTime() + 86400000)]) {
		const resp = await request.fetch(`/apps/carecheck/api/v1/tours/${d.toISOString().slice(0, 10)}`, { headers: dev }).then((r) => r.json())
		const s = (resp?.data?.tours ?? []).flatMap((t) => t.stops).find((x) => x.executorRef === `member:${m.data.id}`)
		if (s) { stopId = s.id; break }
	}
	expect(stopId).toBeGreaterThan(0)

	// checkin + signed-enough bundle → visit status done → draft-eligible
	await request.fetch('/apps/carecheck/api/v1/visits/checkin', {
		method: 'POST', headers: dev,
		data: { tstop_id: stopId, client_id: clientId, idem_key: `flange-ci-${TS}` },
	})
	const sub = await request.fetch('/apps/carecheck/api/v1/visits', {
		method: 'POST', headers: dev,
		data: { tstop_id: stopId, client_id: clientId, idem_key: `flange-v-${TS}`, sig_state: 'unable',
			tasks: [{ task_code: 'meds', status: 'done' }] },
	}).then((r) => r.json())
	expect(sub.success, JSON.stringify(sub)).toBe(true)

	// F14/F13 upstream rows: CUC company + ProjectCheck customer link
	const comp = await sapi(request, 'customercheck', '/api/v1/companies', {
		method: 'POST', data: { name: `FlangeCo ${TS}` },
	})
	companyId = comp?.data?.company?.id ?? comp?.data?.id ?? 0
	expect(companyId, JSON.stringify(comp)).toBeGreaterThan(0)
	const cur = await sapi(request, 'customercheck', `/api/v1/companies/${companyId}`)
	const updatedAt = cur?.data?.company?.updatedAt ?? cur?.data?.updatedAt ?? ''
	const pc = await sapi(request, 'customercheck', `/api/v1/companies/${companyId}/create-pc-customer`, {
		method: 'POST',
		headers: { 'Idempotency-Key': `flange-${TS}` },
		data: { existingPcCustomerId: 0, updatedAt, suggestedName: `FlangeCo ${TS}` },
	})
	pcCustomerId = pc?.data?.pcCustomerId ?? pc?.data?.pc_customer_id ?? 0
	expect(pcCustomerId, JSON.stringify(pc)).toBeGreaterThan(0)
	await ctx.close()
})

test.afterAll(async ({ browser }) => {
	const ctx = await browser.newContext()
	try {
		const req = ctx.request
		if (deviceToken) {
			await req.delete('/apps/carecheck/api/v1/pair', { headers: { 'X-CCK-Device-Token': deviceToken } })
		}
		await login((await ctx.newPage()), CREDS)
		const html = await (await req.get('/apps/carecheck/')).text()
		rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
		if (clientId) {
			await api(req, `/api/clients/${clientId}/crm-link`, { method: 'PUT', data: { crm_company_id: null } })
		}
		if (companyId) {
			await sapi(req, 'customercheck', `/api/v1/companies/${companyId}`, { method: 'DELETE' })
		}
	} finally {
		await ctx.close()
	}
	// drafts/customers minted through the sibling facades — no HTTP delete
	// exists for either, so drop the exact rows this run created
	if (invoiceId) {
		db(`DELETE FROM oc_ic_invoice_lines WHERE invoice_id=${invoiceId}`)
		db(`DELETE FROM oc_ic_invoices WHERE id=${invoiceId}`)
	}
	if (pcCustomerId) { db(`DELETE FROM oc_pc_customers WHERE id=${pcCustomerId}`) }
})

test.beforeEach(async ({ page }) => {
	await login(page, CREDS)
})

test('F12 board duty strip renders the DutyCheck feed or hides cleanly', async ({ page }) => {
	// DOM must mirror the wire: strip hidden iff the feed has no rows
	const duty = await papi(page, `/api/duty-roster?date=${today()}`)
	expect(duty.success).toBe(true)

	await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' })
	const strip = page.locator('[data-cck-duty]')
	await expect(strip).toBeAttached()
	const rows = page.locator('[data-cck-duty-list] li')
	const wire = duty.data.available ? (duty.data.rows || []).length : 0
	if (wire > 0) {
		await expect(strip).toBeVisible()
		await expect(rows).toHaveCount(wire)
		// names render — never a raw member:/uid: token
		for (const txt of await rows.allTextContents()) {
			expect(txt).not.toMatch(/uid:|member:/)
			expect(txt.trim().length).toBeGreaterThan(0)
		}
		const link = page.locator('.cck-duty__link')
		if (await link.count()) {
			await expect(link).toHaveAttribute('href', /dutycheck/)
		}
	} else {
		await expect(strip).toBeHidden()
	}
})

test('F14 CRM picker searches, links, persists and unlinks', async ({ page }) => {
	await page.goto(`/apps/carecheck/#/client/${clientId}`, { waitUntil: 'domcontentloaded' })
	const box = page.locator('[data-cck-crm]')
	await expect(box).toBeVisible()
	const pick = page.locator('[data-cck-crm-pick]')
	await expect(pick).toBeVisible()

	await page.locator('[data-cck-crm-q]').fill(`FlangeCo ${TS}`)
	const hit = page.locator('.cck-crm-pick', { hasText: `FlangeCo ${TS}` })
	await expect(hit).toBeVisible({ timeout: 10000 })
	await hit.click()
	// linked branch: state flips to the deep-link + unlink affordance
	await expect(page.locator('[data-cck-crm-open]')).toBeVisible({ timeout: 10000 })
	await expect(page.locator('[data-cck-crm-unlink]')).toBeVisible()

	// durable postcondition — the link survives a fresh API read
	const re = await papi(page, `/api/clients/${clientId}`)
	expect(re.data.client.crm.id).toBe(companyId)

	await page.locator('[data-cck-crm-unlink]').click()
	const re2 = await papi(page, `/api/clients/${clientId}`)
	expect(re2.data.client.crm.id).toBeNull()
})

test('F13 invoice push: unlinked client errors, linked client drafts + dedupes', async ({ page }) => {
	await page.goto(`/apps/carecheck/#/client/${clientId}`, { waitUntil: 'domcontentloaded' })
	await page.locator('[data-cck-section="exports"]').click()

	const kind = page.locator('[data-cck-invoice-kind]')
	await expect(kind).toBeVisible()
	await expect(kind.locator('option')).toHaveCount(5)
	const push = page.locator('[data-cck-invoice-push]')
	const status = page.locator('[data-cck-invoice-status]')
	await expect(status).toHaveAttribute('aria-live', 'polite')

	// unlinked → coded status text (wire error mapped, not a crash),
	// button re-enables — no dead control after a rejected push
	await push.click()
	await expect.poll(() => status.textContent(), { timeout: 10000 })
		.not.toBe('')
	await expect(push).toBeEnabled()

	// link via API (picker is covered above), then the real push
	const link = await papi(page, `/api/clients/${clientId}/crm-link`, {
		method: 'PUT', data: { crm_company_id: companyId },
	})
	expect(link.success).toBe(true)
	await push.click()
	await expect.poll(() => status.textContent(), { timeout: 10000 })
		.toContain('InvoiceCheck') // i18n: product name is locale-stable
	// read back the invoice id from the wire for cleanup
	const again = await papi(page, `/api/clients/${clientId}/invoice-draft`, {
		method: 'POST', data: { period: today().slice(0, 7), kind: 'haeusliche_betreuung' },
	})
	invoiceId = again?.data?.invoiceId ?? 0
	expect(invoiceId).toBeGreaterThan(0)
	expect(again.data.created).toBe(false) // UI click already created it
})

test('F15 privacy proposal card mirrors the wire state', async ({ page }) => {
	// read the wire first — the DOM branch under test follows it exactly
	const ps = await papi(page, '/api/privacy-proposal')
	expect(ps.success).toBe(true)
	const prop = ps.data.proposal || {}

	await page.goto('/apps/carecheck/#/settings/privacy', { waitUntil: 'domcontentloaded' })
	const state = page.locator('[data-cck-privacy-state]')
	const btn = page.locator('[data-cck-privacy-propose]')
	const open = page.locator('[data-cck-privacy-open]')

	if (!prop.available) {
		// sibling absent → muted copy, neither control renders
		await expect(btn).toBeHidden()
		await expect(open).toBeHidden()
		return
	}
	if (prop.activityId) {
		// already filed: deep link out, CTA hidden (idempotent branch)
		await expect(open).toBeVisible({ timeout: 10000 })
		await expect(open).toHaveAttribute('href', /privacycheck/)
		await expect(btn).toBeHidden()
		return
	}
	// no draft yet — the CTA files it through the real endpoint
	await expect(btn).toBeVisible({ timeout: 10000 })
	await btn.click()
	await expect(open).toBeVisible({ timeout: 10000 })
	await expect(btn).toBeHidden()
	const after = await papi(page, '/api/privacy-proposal')
	expect(after.data.proposal.activityId).toBeGreaterThan(0)
})
