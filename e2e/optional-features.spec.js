/**
 * NH-04 + NH-07 e2e — client-detail photo consent/upload/delete and
 * structured billing exports (JSON + XML) on a real seeded client.
 */
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'
import { createRequire } from 'module'
const require = createRequire(import.meta.url)
const { default: AxeBuilder } = require('@axe-core/playwright')

const TS = Date.now()
const CLIENT_NAME = `NH Client ${TS}`
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }
let rt = ''
let clientId = 0

// 1×1 px PNG (real image — server sniffs via getimagesize, magic bytes alone fail)
const PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64',
)

async function api(request, path, opts = {}) {
  const r = await request.fetch(`/apps/carecheck${path}`, {
    ...opts,
    headers: { 'Content-Type': 'application/json', requesttoken: rt, ...(opts.headers || {}) },
  })
  return r.json()
}

test.beforeAll(async ({ browser }) => {
  const ctx = await browser.newContext()
  const page = await ctx.newPage()
  await login(page, CREDS)
  const request = page.context().request
  rt = ((await (await request.get('/apps/carecheck/')).text()).match(/data-requesttoken="([^"]+)"/) || [])[1] || ''
  expect(rt).not.toBe('')
  const c = await api(request, '/api/clients', { method: 'POST', data: { name: CLIENT_NAME, street: 'NH Allee 1' } })
  expect(c.success).toBe(true)
  clientId = c.data.id
  await ctx.close()
})

test('photo consent gates upload; consented upload lists + deletes', async ({ page }) => {
  await login(page, CREDS)
  await page.goto(`/apps/carecheck/#/client/${clientId}`, { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-client-name]')).toHaveText(CLIENT_NAME)

  // consent off → hint shown, controls hidden, upload refused
  const hint = page.locator('[data-cck-photo-hint]')
  const controls = page.locator('[data-cck-photo-controls]')
  await expect(hint).toBeVisible()
  await expect(controls).toBeHidden()

  // enable consent + save
  await page.locator('input[name="photo_consent"]').check()
  await page.locator('[data-cck-client-edit] button[type=submit]').click()
  await expect(page.locator('[data-cck-photos]')).toBeVisible({ timeout: 10_000 })
  await expect(controls).toBeVisible()

  // upload a real PNG → thumbnail appears with a delete affordance
  await page.locator('[data-cck-photo-file]').setInputFiles({ name: 'wund.png', mimeType: 'image/png', buffer: PNG })
  const fig = page.locator('[data-cck-photos] figure')
  await expect(fig.first()).toBeVisible({ timeout: 10_000 })

  // delete → confirm guard, then row disappears
  page.once('dialog', (d) => d.accept())
  await fig.first().locator('[data-cck-photo-del]').click()
  await expect(page.locator('[data-cck-photos] figure')).toHaveCount(0)
})

test('billing JSON + XML exports download well-formed allow-listed docs', async ({ page }) => {
  await login(page, CREDS)
  await page.goto(`/apps/carecheck/#/client/${clientId}`, { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-client-name]')).toHaveText(CLIENT_NAME)
  await page.locator('[data-cck-export-period]').fill('2026-10')

  const dlJson = page.waitForEvent('download')
  await page.locator('[data-cck-export-json]').click()
  const dj = await dlJson
  expect(dj.suggestedFilename()).toBe(`leistungsnachweis-${clientId}-2026-10.json`)
  const jt = await (await dj.createReadStream()).toArray()
  const doc = JSON.parse(Buffer.concat(jt).toString('utf8'))
  expect(doc.format).toBe('cck-leistungsnachweis')
  expect(doc.client.id).toBe(clientId)

  const dlXml = page.waitForEvent('download')
  await page.locator('[data-cck-export-xml]').click()
  const dx = await dlXml
  expect(dx.suggestedFilename()).toBe(`leistungsnachweis-${clientId}-2026-10.xml`)
  const xt = await (await dx.createReadStream()).toArray()
  const xml = Buffer.concat(xt).toString('utf8')
  expect(xml).toContain('<?xml version="1.0"')
  expect(xml).toContain('<leistungsnachweis')
  expect(xml).toContain('<period>2026-10</period>')
})

test('team management section renders and creates a team', async ({ page }) => {
  await login(page, CREDS)
  await page.goto('/apps/carecheck/#/settings/access', { waitUntil: 'domcontentloaded' })
  const name = `Team ${TS}`
  await page.locator('[data-cck-team-form] input[name=name]').fill(name)
  await page.locator('[data-cck-team-form] button[type=submit]').click()
  await expect(page.locator('[data-cck-team-list]')).toContainText(name, { timeout: 10_000 })

  const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  const appOnly = res.violations.filter((v) => !v.nodes.some((n) => n.html.includes('unified-search')))
  expect(appOnly, JSON.stringify(appOnly.map((v) => v.id))).toEqual([])
})
