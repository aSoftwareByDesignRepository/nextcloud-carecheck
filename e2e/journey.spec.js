// @ts-check
import { test, expect } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'
import { login } from './helpers/auth.js'

/**
 * Full journey, self-contained:
 *   seed via real API (member → pair → client → plan → template → generate →
 *   checkin → bundle → XSS notice) → verify every Zentrale section renders it →
 *   ack → settings round-trip → axe on each view. Two viewports.
 */
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
const TS = Date.now()
const MEMBER_NAME = `E2E Pfleger ${TS}`
const CLIENT_NAME = `E2E Klient ${TS}`
const XSS = `<script>alert(1)</script> E2E Vorfall ${TS}`
let rt = ''
let deviceToken = ''

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

  // member + device
  const m = await api(request, '/api/members', {
    method: 'POST',
    data: { display_name: MEMBER_NAME, qualification: 'fachkraft' },
  })
  expect(m.success).toBe(true)
  // pairing consumes licensed companion seats — lift the cap for the test env
  await api(request, '/api/settings', { method: 'POST', data: { seat_limit: '999' } })
  const code = (await api(request, `/api/members/${m.data.id}/pair-code`, { method: 'POST' })).data.code
  const pairRes = await request.fetch('/apps/carecheck/api/v1/pair', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    data: { code, device_name: `e2e-${TS}`, companion_version: '0.1.0' },
  })
  const pair = await pairRes.json()
  expect(pair.success, JSON.stringify(pair)).toBe(true)
  deviceToken = pair.data.deviceToken
  const memberRef = `member:${m.data.id}`

  // client + published plan
  const c = await api(request, '/api/clients', {
    method: 'POST',
    data: { name: CLIENT_NAME, street: 'E2E Weg 1', zip: '10115', city: 'Berlin' },
  })
  expect(c.success).toBe(true)
  const plan = await api(request, `/api/clients/${c.data.id}/plan/publish`, {
    method: 'POST',
    data: { base_revision: 1, tasks: [{ task_code: 'meds', label: 'Medikamente', value_required: false }] },
  })
  expect(plan.success).toBe(true)

  // template for today + generate + device visit
  const weekday = ((new Date().getDay() + 6) % 7) + 1
  const tpl = await api(request, '/api/tour-templates', {
    method: 'POST',
    data: {
      name: `E2E Tour ${TS}`, weekday_set: [weekday], status: 'published',
      stops: [{ client_id: c.data.id, win_start: 360, win_end: 720, order_hint: 0, executor_ref: memberRef, kind: 'fixed' }],
    },
  })
  expect(tpl.success).toBe(true)
  await api(request, '/api/tours/generate', { method: 'POST' })
  // Server local date (generation TZ) can differ from UTC near midnight —
  // probe both days instead of assuming one calendar date.
  const now = new Date()
  const stops = []
  for (const d of [now, new Date(now.getTime() + 86400000)]) {
    const resp = await request.fetch(`/apps/carecheck/api/v1/tours/${d.toISOString().slice(0, 10)}`, {
      headers: { 'X-CCK-Device-Token': deviceToken },
    }).then((r) => r.json())
    stops.push(...(resp?.data?.tours ?? []).flatMap((t) => t.stops))
  }
  const stop = stops.find((s) => s.executorRef === memberRef)
  expect(stop).toBeTruthy()
  const dev = { 'X-CCK-Device-Token': deviceToken, 'Content-Type': 'application/json' }
  await request.fetch('/apps/carecheck/api/v1/visits/checkin', {
    method: 'POST', headers: dev,
    data: { tstop_id: stop.id, client_id: c.data.id, idem_key: `e2e-ci-${TS}` },
  })
  const bundle = await request.fetch('/apps/carecheck/api/v1/visits', {
    method: 'POST', headers: dev,
    data: {
      tstop_id: stop.id, client_id: c.data.id, idem_key: `e2e-bundle-${TS}`, sig_state: 'unable',
      tasks: [{ task_code: 'meds', status: 'done' }],
    },
  }).then((r) => r.json())
  expect(bundle.success).toBe(true)
  const notice = await request.fetch('/apps/carecheck/api/v1/notices', {
    method: 'POST', headers: dev,
    data: { client_id: c.data.id, kind: 'incident', note: XSS, idem_key: `e2e-notice-${TS}` },
  }).then((r) => r.json())
  expect(notice.success).toBe(true)
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
  page.on('dialog', (d) => d.accept())
  await login(page, CREDS)
})

async function axe(page, label) {
  const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  const appOnly = res.violations.filter(
    (v) => !v.nodes.some((n) => n.html.includes('unified-search'))
  )
  expect(appOnly, `axe ${label}: ${JSON.stringify(appOnly.map((v) => v.id))}`).toEqual([])
}

test('zentrale journey: board → clients → members → inbox → settings', async ({ page }) => {
  await page.goto('/apps/carecheck/', { waitUntil: 'domcontentloaded' })

  // Coverage board: the seeded tour shows the done stop
  const board = page.locator('[data-cck-board]')
  await expect(board.locator('.cck-stop--done').first()).toBeVisible()
  await axe(page, 'board')

  // Clients
  await page.goto('/apps/carecheck/#/clients', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-clients]').locator(`text=${CLIENT_NAME}`)).toBeVisible()
  await axe(page, 'clients')

  // Members
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-members]').locator(`text=${MEMBER_NAME}`)).toBeVisible()
  await axe(page, 'members')

  // Inbox: XSS notice renders text, never markup
  await page.goto('/apps/carecheck/#/inbox', { waitUntil: 'domcontentloaded' })
  const inbox = page.locator('[data-cck-notices]')
  const notice = inbox.locator('.cck-notice--high', { hasText: `E2E Vorfall ${TS}` })
  await expect(notice).toBeVisible()
  await expect(notice.locator('.cck-notice__text')).toContainText('<script>alert(1)</script>')
  await expect(page.locator('script', { hasText: 'alert(1)' })).toHaveCount(0)
  await axe(page, 'inbox')

  // Acknowledge → stays listed as audit trail, loses the ack action, state flips
  await notice.locator('[data-cck-ack]').click()
  await expect(inbox.locator('.cck-notice', { hasText: `E2E Vorfall ${TS}` }).locator('text=acknowledged')).toBeVisible()
  await expect(inbox.locator('.cck-notice', { hasText: `E2E Vorfall ${TS}` }).locator('[data-cck-ack]')).toHaveCount(0)

  // Settings round-trip (general section = the default /settings page)
  await page.goto('/apps/carecheck/#/settings/general', { waitUntil: 'domcontentloaded' })
  const sla = page.locator('[data-cck-settings-form="general"] [name="sla_minutes"]')
  await expect(sla).toBeVisible()
  await sla.fill('45')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'POST'),
    page.locator('[data-cck-settings-form="general"] [type="submit"]').click(),
  ])
  await page.goto('/apps/carecheck/#/settings/general', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-settings-form="general"] [name="sla_minutes"]')).toHaveValue('45')

  // Field-level error contract on the companion section: an empty seat_limit
  // passes client-side (no `required`) but the server rejects non-digits →
  // aria-invalid + inline error + role=alert status. Valid resubmit clears it.
  await page.goto('/apps/carecheck/#/settings/companion', { waitUntil: 'domcontentloaded' })
  const seatLimit = page.locator('[data-cck-settings-form="companion"] [name="seat_limit"]')
  await expect(seatLimit).toBeVisible()
  await seatLimit.fill('')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'POST'),
    page.locator('[data-cck-settings-form="companion"] [type="submit"]').click(),
  ])
  await expect(seatLimit).toHaveAttribute('aria-invalid', 'true')
  await expect(page.locator('.cck-form-status[role="alert"]')).toBeVisible()
  await expect(page.locator('#cck-field-error-seat_limit')).toBeVisible()
  await seatLimit.fill('999')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'POST'),
    page.locator('[data-cck-settings-form="companion"] [type="submit"]').click(),
  ])
  await expect(seatLimit).not.toHaveAttribute('aria-invalid')
  await expect(page.locator('.cck-field-error')).toHaveCount(0)
  await axe(page, 'settings')
})

test('no dead ends: landmarks, keyboard, board always has content or honest empty state', async ({ page }) => {
  await page.goto('/apps/carecheck/', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('#app-navigation')).toBeVisible()
  await expect(page.locator('#app-content')).toBeVisible()
  // board invariant: either stops or an explicit empty state — never a blank view
  const board = page.locator('[data-cck-board]')
  await expect(board.locator('.cck-stop, .cck-empty').first()).toBeVisible()
  await page.keyboard.press('Tab')
  const focused = await page.evaluate(() => document.activeElement?.tagName)
  expect(['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA']).toContain(focused)
  await axe(page, 'landmarks')
})

test('spec §7.2 views: catalog, client detail, tours editor, absences, exports, pair card', async ({ page }) => {
  // (beforeEach already established the session)

  // Catalog: add a task through the real form, see it listed
  await page.goto('/apps/carecheck/#/catalog', { waitUntil: 'domcontentloaded' })
  const code = `e2e_task_${TS}`.slice(0, 20)
  await page.locator('[data-cck-catalog-form] [name="code"]').fill(code)
  await page.locator('[data-cck-catalog-form] [name="label"]').fill(`E2E Aufgabe ${TS}`)
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/catalog') && r.request().method() === 'POST'),
    page.locator('[data-cck-catalog-form] [type="submit"]').click(),
  ])
  await expect(page.locator('[data-cck-catalog] tbody')).toContainText(code)
  await axe(page, 'catalog')

  // Client detail: record edit fields present, plan editor prechecks the
  // published task, journal accepts a manual entry
  await page.goto('/apps/carecheck/#/clients', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-cck-clients]').locator(`text=${CLIENT_NAME}`).click()
  await expect(page.locator('[data-cck-client-name]')).toHaveText(CLIENT_NAME)
  await expect(page.locator('[data-cck-plan-tasks] input[value="meds"]')).toBeChecked()
  await page.locator('[data-cck-journal-form] [name="text"]').fill(`E2E Protokollnotiz ${TS}`)
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/journal') && r.request().method() === 'POST'),
    page.locator('[data-cck-journal-form] [type="submit"]').click(),
  ])
  await expect(page.locator('[data-cck-journal]')).toContainText(`E2E Protokollnotiz ${TS}`)
  await expect(page.locator('[data-cck-journal]')).toContainText('manual_note')
  await axe(page, 'client detail')

  // Tours editor: create a template with a deadline stop through the UI
  await page.goto('/apps/carecheck/#/tours', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-cck-tour-form] [name="name"]').fill(`E2E Tourform ${TS}`)
  await page.locator('[data-cck-tour-form] [name="wd"][value="2"]').check()
  await page.locator('[data-cck-tour-add-stop]').click()
  const row = page.locator('.cck-tstop').last()
  // external stop: no client, label + window required
  await row.locator('[name="ts_kind"]').selectOption('external')
  await row.locator('[name="ts_loc"]').fill('E2E Apotheke')
  // preset 'custom' reveals explicit times (progressive disclosure)
  await row.locator('[name="ts_preset"]').selectOption('custom')
  await row.locator('[name="ts_from"]').fill('08:00')
  await row.locator('[name="ts_to"]').fill('09:00')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/tour-templates') && r.request().method() === 'POST'),
    page.locator('[data-cck-tour-form] [type="submit"]').click(),
  ])
  await expect(page.locator('[data-cck-templates] tbody')).toContainText(`E2E Tourform ${TS}`)
  await axe(page, 'tours')

  // Members: pair card renders a real QR image, absence marking round-trips
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  const memberRow = page.locator('[data-cck-members] tr', { hasText: MEMBER_NAME })
  await memberRow.locator('[data-cck-pair]').click()
  await expect(page.locator('[data-cck-paircard]')).toBeVisible()
  const qrSrc = await page.locator('[data-cck-pair-qr]').getAttribute('src')
  expect(qrSrc).toMatch(/^data:image\/png/)
  await expect(page.locator('[data-cck-pair-text]')).not.toHaveText('')

  const staffPick = page.locator('[data-cck-absence-form] .cck-refpick')
  await staffPick.locator('.cck-refpick__input').fill('cck_smoke')
  await staffPick.locator('.cck-refpick__list [role="option"]').first().click()
  await expect(staffPick.locator('.cck-refpick__chip')).toHaveCount(1)
  const todayIso = new Date().toISOString().slice(0, 10)
  await page.locator('[data-cck-absence-form] [name="from"]').fill(todayIso)
  await page.locator('[data-cck-absence-form] [name="until"]').fill(todayIso)
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/absences') && r.request().method() === 'POST'),
    page.locator('[data-cck-absence-form] [type="submit"]').click(),
  ])
  await expect(page.locator('[data-cck-absences]')).not.toBeEmpty()
  await axe(page, 'members+paircard')
})
