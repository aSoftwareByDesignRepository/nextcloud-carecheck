// @ts-check
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

/**
 * Simplification-pass contract: the flows a Zentrale actually touches must be
 * one-tap, self-labelled, and forgiving. Covers what the journey spec does not:
 * - review-queue resolution uses a real <dialog> (never window.prompt)
 * - destructive actions ask once via confirm()
 * - tour stops pick clients by name (never raw IDs)
 * - submit/one-shot buttons go aria-busy while in flight (double-tap safe)
 * - the pair card is dismissible, the assign dialog autofocuses its search
 * Seeds: member(+device) → client → template → generate → rejected bundle.
 */
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
const TS = Date.now()
const MEMBER_NAME = `UX Pfleger ${TS}`
const CLIENT_NAME = `UX Klient ${TS}`
let rt = ''
let deviceToken = ''
let stopId = 0

async function api(request, path, opts = {}) {
  const res = await request.fetch(`/apps/carecheck${path}`, {
    ...opts,
    headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
  })
  return res.json()
}

test.beforeAll(async ({ browser }) => {
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await login(page, CREDS)
  const request = page.context().request
  const html = await (await request.get('/apps/carecheck/')).text()
  rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
  expect(rt).not.toBe('')

  const m = await api(request, '/api/members', { method: 'POST', data: { display_name: MEMBER_NAME, qualification: 'fachkraft' } })
  expect(m.success).toBe(true)
  await api(request, '/api/settings', { method: 'POST', data: { seat_limit: '999' } })
  const code = (await api(request, `/api/members/${m.data.id}/pair-code`, { method: 'POST' })).data.code
  const pair = await request.fetch('/apps/carecheck/api/v1/pair', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    data: { code, device_name: `ux-${TS}`, companion_version: '0.1.0' },
  }).then((r) => r.json())
  expect(pair.success, JSON.stringify(pair)).toBe(true)
  deviceToken = pair.data.deviceToken

  const c = await api(request, '/api/clients', { method: 'POST', data: { name: CLIENT_NAME, street: 'UX Weg 1' } })
  expect(c.success).toBe(true)
  const plan = await api(request, `/api/clients/${c.data.id}/plan/publish`, {
    method: 'POST', data: { base_revision: 1, tasks: [{ task_code: 'meds', label: 'Medikamente', value_required: false }] },
  })
  expect(plan.success).toBe(true)

  const weekday = ((new Date().getDay() + 6) % 7) + 1
  const tpl = await api(request, '/api/tour-templates', {
    method: 'POST',
    data: {
      name: `UX Tour ${TS}`, weekday_set: [weekday], status: 'published',
      stops: [
        { client_id: c.data.id, win_start: 360, win_end: 720, order_hint: 0, executor_ref: `member:${m.data.id}`, kind: 'fixed' },
        { client_id: c.data.id, win_start: 720, win_end: 840, order_hint: 1, kind: 'window' }, // no executor → uncovered
      ],
    },
  })
  expect(tpl.success).toBe(true)
  await api(request, '/api/tours/generate', { method: 'POST' })

  // find today's materialized stop (server-local date may differ — probe both)
  const dev = { 'X-CCK-Device-Token': deviceToken, 'Content-Type': 'application/json' }
  const now = new Date()
  for (const d of [now, new Date(now.getTime() + 86400000)]) {
    const resp = await request.fetch(`/apps/carecheck/api/v1/tours/${d.toISOString().slice(0, 10)}`, { headers: dev }).then((r) => r.json())
    const s = (resp?.data?.tours ?? []).flatMap((t) => t.stops).find((x) => x.executorRef === `member:${m.data.id}`)
    if (s) { stopId = s.id; break }
  }
  expect(stopId).toBeGreaterThan(0)

  // a structurally-invalid bundle lands in the review queue (never dropped)
  const rej = await request.fetch('/apps/carecheck/api/v1/visits', {
    method: 'POST', headers: dev,
    data: { tstop_id: stopId, client_id: c.data.id, idem_key: `ux-rej-${TS}`, sig_state: 'unable',
            plan_version_id: 999999, tasks: [{ task_code: 'meds', status: 'done' }] },
  })
  expect(rej.ok()).toBe(false) // rejected → quarantined in the review queue
  await ctx.close()
})

test.afterAll(async ({ browser }) => {
  // seat hygiene: the test device unpairs itself so repeated runs never
  // exhaust the license cap (NO_SEAT flakes).
  if (!deviceToken) { return }
  const ctx = await browser.newContext()
  try {
    const req = ctx.request
    await req.delete('/apps/carecheck/api/v1/pair', {
      headers: { 'X-CCK-Device-Token': deviceToken },
    })
  } finally {
    await ctx.close()
  }
})

test.beforeEach(async ({ page }) => {
  await login(page, CREDS)
})

test('review queue resolves through a real dialog, not window.prompt', async ({ page }) => {
  const prompts = []
  page.on('dialog', (d) => { prompts.push(d.type()); return d.dismiss() })
  await page.goto('/apps/carecheck/#/inbox', { waitUntil: 'domcontentloaded' })

  const resolve = page.locator('[data-cck-resolve]').first()
  await expect(resolve).toBeVisible()
  await resolve.click()

  const dlg = page.locator('[data-cck-resolve-dialog]')
  await expect(dlg).toBeVisible()
  const note = dlg.locator('[data-cck-resolve-note]')
  await expect(note).toBeFocused()

  // empty note is refused inline — no silent drop, no blocking prompt
  await dlg.locator('[data-cck-resolve-confirm]').click()
  await expect(note).toHaveAttribute('aria-invalid', 'true')
  await expect(dlg).toBeVisible()

  await note.fill('corrected: visit was re-submitted with the right plan')
  await dlg.locator('[data-cck-resolve-confirm]').click()
  await expect(dlg).toBeHidden()
  await expect(page.locator('[data-cck-banner]')).toContainText('Resolved')
  expect(prompts).toEqual([]) // no window.prompt anywhere in the flow
})

test('destructive actions ask once via confirm and respect cancel', async ({ page }) => {
  await page.goto('/apps/carecheck/#/clients', { waitUntil: 'domcontentloaded' })
  const row = page.locator('[data-cck-clients] tr', { hasText: CLIENT_NAME })
  await expect(row).toBeVisible()

  const seen = []
  page.on('dialog', (d) => { seen.push(d.message()); return d.dismiss() })
  await row.locator('[data-cck-archive]').click()
  expect(seen.length).toBe(1)
  expect(seen[0].length).toBeGreaterThan(10) // a real sentence, not a raw key
  await expect(row).toBeVisible() // cancelled — client still there
})

test('tour stop picks a client by name — never a raw id', async ({ page }) => {
  await page.goto('/apps/carecheck/#/tours', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-cck-tour-add-stop]').click()
  const sel = page.locator('.cck-tstop select[name=ts_client]').last()
  await expect(sel).toBeVisible()
  const option = sel.locator('option', { hasText: CLIENT_NAME })
  await expect(option).toHaveCount(1)
})

test('one-shot buttons go aria-busy while the request is in flight', async ({ page }) => {
  await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' })
  await page.route('**/apps/carecheck/api/tours/generate', async (route) => {
    await new Promise((r) => setTimeout(r, 600))
    return route.continue()
  })
  const btn = page.locator('[data-cck-generate]')
  await btn.click()
  await expect(btn).toHaveAttribute('aria-busy', 'true')
  await expect(btn).toBeDisabled()
  await expect(btn).not.toHaveAttribute('aria-busy', 'true', { timeout: 10_000 })
})

test('pair card dismisses; assign dialog autofocuses its search', async ({ page }) => {
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  const row = page.locator('[data-cck-members] tr', { hasText: MEMBER_NAME })
  await row.locator('[data-cck-pair]').click()
  const card = page.locator('[data-cck-paircard]')
  await expect(card).toBeVisible()
  await card.locator('[data-cck-paircard-done]').click()
  await expect(card).toBeHidden()

  // uncovered stop → Assign… → dialog opens with search focused
  await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' })
  const assign = page.locator('[data-cck-assign]').first()
  await expect(assign).toBeVisible()
  await assign.click()
  const dlg = page.locator('[data-cck-assign-dialog]')
  await expect(dlg).toBeVisible()
  await expect(dlg.locator('.cck-refpick__input')).toBeFocused()
})

test('board tour headings carry the template name', async ({ page }) => {
  await page.goto('/apps/carecheck/', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-board] .cck-tour h2', { hasText: `UX Tour ${TS}` })).toBeVisible()
})
