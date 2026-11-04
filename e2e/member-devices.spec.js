// @ts-check
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

/**
 * Intake 2026-11-04 contract: a single lost phone is revocable from the
 * member row without deactivating the member, and the delete-template
 * confirm names the real keep-alive window (horizon_days), never a stale
 * "today and tomorrow".
 * Seeds: member + redeemed device via the API; cleans up by self-unpair.
 */
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
const TS = Date.now()
const MEMBER_NAME = `Dev Pfleger ${TS}`
const DEVICE_LABEL = `dev-handy-${TS}`
let rt = ''
let deviceToken = ''
let memberId = 0

async function api(request, path, opts = {}) {
  const res = await request.fetch(`/apps/carecheck${path}`, {
    ...opts,
    headers: { requesttoken: rt, 'Content-Type': 'application/json', ...(opts.headers || {}) },
  })
  return res.json()
}

test.beforeAll(async ({ browser }, testInfo) => {
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
  memberId = m.data.id
  const code = (await api(request, `/api/members/${memberId}/pair-code`, { method: 'POST' })).data.code
  // pair redemption is IP-rate-limited (10/10min fixed window) — retry past it
  let pair = { success: false }
  for (let attempt = 0; attempt < 25 && !pair.success; attempt++) {
    pair = await request.fetch('/apps/carecheck/api/v1/pair', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      data: { code, device_label: DEVICE_LABEL, companion_version: '0.1.0' },
    }).then((r) => r.json())
    if (pair.success || pair.error !== 'RATE_LIMITED') { break }
    await new Promise((res) => setTimeout(res, 30000))
  }
  expect(pair.success, JSON.stringify(pair)).toBe(true)
  deviceToken = pair.data.deviceToken

  // a template row so the delete-confirm leg always has a button
  const tpl = await api(request, '/api/tour-templates', {
    method: 'POST',
    data: { name: `Dev Tour ${TS}`, weekday_set: [1, 2, 3, 4, 5, 6, 7], status: 'published', stops: [] },
  })
  expect(tpl.success).toBe(true)
  await ctx.close()
})

test.afterAll(async ({ browser }) => {
  if (!deviceToken) { return }
  const ctx = await browser.newContext()
  const req = ctx.request
  // self-unpair frees the seat for the next run
  await req.delete('/apps/carecheck/api/v1/pair', {
    headers: { 'X-CCK-Device-Token': deviceToken },
  }).catch(() => {})
  await ctx.close()
})

test.beforeEach(async ({ page }) => {
  await login(page, CREDS)
})

test('member devices dialog lists the paired device and revokes it durably', async ({ page }) => {
  await page.goto('/apps/carecheck/#/members', { waitUntil: 'domcontentloaded' })
  const row = page.locator('[data-cck-members] tr', { hasText: MEMBER_NAME })
  await expect(row).toBeVisible()
  await expect(row.locator('[data-cck-devices]')).toBeVisible()

  await row.locator('[data-cck-devices]').click()
  const dlg = page.locator('dialog[data-cck-devices-dialog]')
  await expect(dlg).toBeVisible()
  await expect(dlg.locator('[data-cck-devices-hint]')).toHaveText(MEMBER_NAME)
  const item = dlg.locator('.cck-device-list__item', { hasText: DEVICE_LABEL })
  await expect(item).toBeVisible()

  // cancel once: no mutation
  await item.locator('[data-cck-device-revoke]').click()
  const confirm = page.locator('dialog[data-cck-confirm-dialog]')
  await expect(confirm).toBeVisible()
  await confirm.locator('[data-cck-confirm-cancel]').click()
  await expect(confirm).not.toBeVisible()
  await expect(item).toBeVisible()

  // confirm: row leaves the dialog and the server agrees
  await item.locator('[data-cck-device-revoke]').click()
  await expect(confirm).toBeVisible()
  await confirm.locator('[data-cck-confirm-ok]').click()
  await expect(dlg.locator('[data-cck-device-revoke]')).toHaveCount(0)
  await expect(dlg.locator('.cck-device-list__item')).toHaveCount(1) // empty-state row

  const members = await page.request.get('/apps/carecheck/api/members').then((r) => r.json())
  const me = (members.data?.members ?? []).find((m) => m.id === memberId)
  expect(me?.devices ?? []).toEqual([])
})

test('delete-template confirm names the configured horizon window', async ({ page }) => {
  const settings = await page.request.get('/apps/carecheck/api/settings').then((r) => r.json())
  const horizon = String(settings.data?.settings?.horizon_days ?? '')

  await page.goto('/apps/carecheck/#/tours', { waitUntil: 'domcontentloaded' })
  const del = page.locator('[data-cck-tpl-del]').first()
  await expect(del).toBeVisible()
  await del.click()
  const confirm = page.locator('dialog[data-cck-confirm-dialog]')
  await expect(confirm).toBeVisible()
  // locale-safe: the configured day count is a digit string, not a word
  await expect(confirm.locator('[data-cck-confirm-text]')).toContainText(horizon)
  await confirm.locator('[data-cck-confirm-cancel]').click()
})
