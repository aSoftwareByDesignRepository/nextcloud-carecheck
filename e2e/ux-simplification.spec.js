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

// the beforeAll seeding session's CSRF token is useless inside tests — the
// fixture page logs in fresh. Fetch the page's own token.
async function papi(page, path, opts = {}) {
  const html = await (await page.request.get('/apps/carecheck/')).text()
  const token = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
  return api(page.request, path, { ...opts, headers: { requesttoken: token } })
}

test.beforeAll(async ({ browser }, testInfo) => {
  // pair redemption may have to wait out a 10-minute rate-limit window
  testInfo.setTimeout(13 * 60 * 1000)
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
  // pair redemption is IP-rate-limited (10/10min fixed window) — repeated suite
  // runs exhaust it, so retry until the window rolls over instead of flaking.
  let pair = { success: false }
  for (let attempt = 0; attempt < 25 && !pair.success; attempt++) {
    pair = await request.fetch('/apps/carecheck/api/v1/pair', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      data: { code, device_name: `ux-${TS}`, companion_version: '0.1.0' },
    }).then((r) => r.json())
    if (pair.success || pair.error !== 'RATE_LIMITED') { break }
    await new Promise((res) => setTimeout(res, 30000))
  }
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

test('destructive actions ask once via styled confirm and respect cancel', async ({ page }) => {
  await page.goto('/apps/carecheck/#/clients', { waitUntil: 'domcontentloaded' })
  const row = page.locator('[data-cck-clients] tr', { hasText: CLIENT_NAME })
  await expect(row).toBeVisible()

  let nativeDialogs = 0
  page.on('dialog', (d) => { nativeDialogs++; return d.dismiss() })
  await row.locator('[data-cck-archive]').click()
  const dlg = page.locator('[data-cck-confirm-dialog]')
  await expect(dlg).toBeVisible()
  const hint = await dlg.locator('[data-cck-confirm-text]').textContent()
  expect(hint.length).toBeGreaterThan(10) // a real sentence, not a raw key
  expect(nativeDialogs).toBe(0) // never window.confirm

  // cancel path — client untouched
  await dlg.locator('[data-cck-confirm-cancel]').click()
  await expect(dlg).toBeHidden()
  await expect(row).toBeVisible()

  // Escape also cancels
  await row.locator('[data-cck-archive]').click()
  await expect(dlg).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dlg).toBeHidden()
  await expect(row).toBeVisible()
})

test('added tour stops are individually removable — no full reset needed', async ({ page }) => {
  await page.goto('/apps/carecheck/#/tours', { waitUntil: 'domcontentloaded' })
  await page.locator('[data-cck-tour-add-stop]').click()
  await page.locator('[data-cck-tour-add-stop]').click()
  await expect(page.locator('.cck-tstop')).toHaveCount(2)
  await page.locator('.cck-tstop .cck-tstop__rm').first().click()
  await expect(page.locator('.cck-tstop')).toHaveCount(1)
  // the remaining row + form fields survive — removal is surgical
  await expect(page.locator('[data-cck-tour-form] [name="name"]')).toBeVisible()
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

test('board stops link straight to the client record', async ({ page }) => {
  await page.goto('/apps/carecheck/#/board', { waitUntil: 'domcontentloaded' })
  const link = page.locator('[data-cck-board] a[href^="#/client/"]', { hasText: CLIENT_NAME }).first()
  await expect(link).toBeVisible()
  await link.click()
  await expect(page.locator('[data-cck-client-name]')).toHaveText(CLIENT_NAME)
})

test('inbox notices render a localized kind label, not a raw token', async ({ page }) => {
  // seed an incident via the paired device
  const dev = { 'X-CCK-Device-Token': deviceToken, 'Content-Type': 'application/json' }
  const res = await page.request.fetch('/apps/carecheck/api/v1/notices', {
    method: 'POST', headers: dev,
    data: { kind: 'supply_needed', note: `UX notice ${TS}`, idem_key: `ux-kind-${TS}` },
  })
  expect(res.ok()).toBe(true)
  await page.goto('/apps/carecheck/#/inbox', { waitUntil: 'domcontentloaded' })
  const notice = page.locator('.cck-notice', { hasText: `UX notice ${TS}` })
  await expect(notice).toBeVisible()
  await expect(notice.locator('strong')).toHaveText('Supply needed')
  await expect(notice).not.toContainText('supply_needed')
})

test('template rows are editable and deletable — no write-once dead end', async ({ page }) => {
  // fresh template via API so this test owns its row
  const t = await papi(page, '/api/tour-templates', {
    method: 'POST',
    data: { name: `UX Edit ${TS}`, weekday_set: [3], status: 'draft',
            stops: [{ kind: 'external', loc_text: 'Pharmacy', win_start: 600, win_end: 660, order_hint: 0 }] },
  })
  expect(t.success).toBe(true)

  await page.goto('/apps/carecheck/#/tours', { waitUntil: 'domcontentloaded' })
  const row = page.locator('[data-cck-templates] tr', { hasText: `UX Edit ${TS}` })
  await expect(row).toBeVisible()

  // Edit populates the whole form — name, weekday, status, stops
  await row.locator('[data-cck-tpl-edit]').click()
  const form = page.locator('[data-cck-tour-form]')
  await expect(form.locator('[name="name"]')).toHaveValue(`UX Edit ${TS}`)
  await expect(form.locator('[name="wd"][value="3"]')).toBeChecked()
  await expect(form.locator('[name="status"]')).toHaveValue('draft')
  await expect(page.locator('.cck-tstop')).toHaveCount(1)
  await expect(page.locator('.cck-tstop [name="ts_loc"]')).toHaveValue('Pharmacy')
  await expect(page.locator('[data-cck-tour-cancel]')).toBeVisible()

  await form.locator('[name="name"]').fill(`UX Edited ${TS}`)
  await form.locator('[name="status"]').selectOption('published')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/tour-templates/${t.data.id}`) && r.request().method() === 'PUT'),
    form.locator('[type="submit"]').click(),
  ])
  const edited = page.locator('[data-cck-templates] tr', { hasText: `UX Edited ${TS}` })
  await expect(edited).toBeVisible()
  await expect(edited).toContainText('Published')

  // Delete via the styled confirm — never a dead row
  await edited.locator('[data-cck-tpl-del]').click()
  const dlg = page.locator('[data-cck-confirm-dialog]')
  await expect(dlg).toBeVisible()
  await dlg.locator('[data-cck-confirm-ok]').click()
  await expect(page.locator('[data-cck-templates] tr', { hasText: `UX Edited ${TS}` })).toHaveCount(0)
})

test('member and catalog rows are editable — typos are not permanent', async ({ page }) => {
  // member
  const m = await papi(page, '/api/members', {
    method: 'POST', data: { display_name: `UX EditMe ${TS}`, qualification: 'hilfskraft' },
  })
  expect(m.success).toBe(true)
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  const mrow = page.locator('[data-cck-members] tr', { hasText: `UX EditMe ${TS}` })
  await mrow.locator('[data-cck-member-edit]').click()
  const mform = page.locator('[data-cck-member-form]')
  await expect(mform.locator('[name="display_name"]')).toHaveValue(`UX EditMe ${TS}`)
  await expect(page.locator('[data-cck-member-cancel]')).toBeVisible()
  await mform.locator('[name="display_name"]').fill(`UX Renamed ${TS}`)
  await mform.locator('[name="qualification"]').selectOption('betreuer')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/members/${m.data.id}`) && r.request().method() === 'PUT'),
    mform.locator('[type="submit"]').click(),
  ])
  const renamed = page.locator('[data-cck-members] tr', { hasText: `UX Renamed ${TS}` })
  await expect(renamed).toBeVisible()
  await expect(renamed).toContainText('Caregiver')

  // catalog
  const t = await papi(page, '/api/catalog', {
    method: 'POST', data: { code: `uxtask-${TS}`, label: `UX Task ${TS}`, requires_qual: null },
  })
  expect(t.success).toBe(true)
  await page.goto('/apps/carecheck/#/catalog', { waitUntil: 'domcontentloaded' })
  const trow = page.locator('[data-cck-catalog] tr', { hasText: `UX Task ${TS}` })
  await trow.locator('[data-cck-cat-edit]').click()
  const tform = page.locator('[data-cck-catalog-form]')
  await expect(tform.locator('[name="code"]')).toBeDisabled() // code is the plan reference — immutable
  await tform.locator('[name="label"]').fill(`UX Task renamed ${TS}`)
  await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/api/catalog/${t.data.id}`) && r.request().method() === 'PUT'),
    tform.locator('[type="submit"]').click(),
  ])
  await expect(page.locator('[data-cck-catalog] tr', { hasText: `UX Task renamed ${TS}` })).toBeVisible()
})

test('absence until defaults to from — same-day sick note is one field', async ({ page }) => {
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  const todayIso = new Date().toISOString().slice(0, 10)
  await page.locator('[data-cck-absence-form] [name="from"]').fill(todayIso)
  await expect(page.locator('[data-cck-absence-form] [name="until"]')).toHaveValue(todayIso)
})

test('time-of-day presets are HH:MM in the UI and minutes on the wire', async ({ page }) => {
  await page.goto('/apps/carecheck/#/settings/general', { waitUntil: 'domcontentloaded' })
  const preset = page.locator('[data-cck-settings-form="general"] [name="preset_early"]')
  await expect(preset).toHaveValue(/\d{2}:\d{2}-\d{2}:\d{2}/)
  await preset.fill('06:30-09:30')
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/settings') && r.request().method() === 'POST'),
    page.locator('[data-cck-settings-form="general"] [type="submit"]').click(),
  ])
  // wire format is minutes — verify through the API, not the input
  const s = await papi(page, '/api/settings')
  expect(s.data.settings.preset_early).toBe('390-570')
})

test('template PUT enforces CAS and uniform 404 — no existence oracle', async ({ page }) => {
  const t = await papi(page, '/api/tour-templates', {
    method: 'POST',
    data: { name: `UX CAS ${TS}`, weekday_set: [4], status: 'draft',
            stops: [{ kind: 'external', loc_text: 'Depot', win_start: 480, win_end: 540, order_hint: 0 }] },
  })
  expect(t.success).toBe(true)

  // stale revision → 409 conflict, never a silent merge
  const stale = await papi(page, `/api/tour-templates/${t.data.id}`, {
    method: 'PUT',
    data: { name: 'X', weekday_set: [4], status: 'draft', base_revision: 99,
            stops: [{ kind: 'external', loc_text: 'Depot', win_start: 480, win_end: 540, order_hint: 0 }] },
  })
  expect(stale.success).toBe(false)
  expect(stale.error).toBe('CONFLICT')

  // missing revision token → conflict too (fail-closed, api-security §3)
  const noRev = await papi(page, `/api/tour-templates/${t.data.id}`, {
    method: 'PUT', data: { name: 'X', weekday_set: [4], status: 'draft', stops: [] },
  })
  expect(noRev.success).toBe(false)
  expect(noRev.error).toBe('CONFLICT')

  // nonexistent id collapses to the same NOT_FOUND for PUT and DELETE
  const ghost = await papi(page, '/api/tour-templates/99999999', {
    method: 'PUT', data: { name: 'X', weekday_set: [4], status: 'draft', base_revision: 1, stops: [] },
  })
  const ghostDel = await papi(page, '/api/tour-templates/99999999', { method: 'DELETE' })
  for (const g of [ghost, ghostDel]) {
    expect(g.success).toBe(false)
    expect(g.error).toBe('NOT_FOUND')
  }

  // catalog: code stays immutable even if a body tries to smuggle one in
  const cat = await papi(page, '/api/catalog', {
    method: 'POST', data: { code: `ux-imm-${TS}`, label: 'Original label', requires_qual: null },
  })
  expect(cat.success).toBe(true)
  const upd = await papi(page, `/api/catalog/${cat.data.id}`, {
    method: 'PUT', data: { code: 'hijacked-code', label: 'Renamed label', requires_qual: 'fachkraft' },
  })
  expect(upd.success).toBe(true)
  const list = await papi(page, '/api/catalog')
  const row = list.data.tasks.find((x) => x.id === cat.data.id)
  expect(row.code).toBe(`ux-imm-${TS}`)
  expect(row.label).toBe('Renamed label')
  expect(row.requiresQual).toBe('fachkraft')
})
