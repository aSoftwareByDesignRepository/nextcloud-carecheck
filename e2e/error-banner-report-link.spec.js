// @ts-check
import { test, expect } from '@playwright/test'
import { login } from './helpers/auth.js'

/**
 * Error-banner report-link contract (DS §13 / app-feedback family contract):
 * the app-owned [data-cck-banner] is a replace-text toast the shared
 * SbdAppFeedback toast hook never sees, so error banners must attach their
 * own "Report this problem" mailto next to the message.
 *
 * Structural selectors only — the fixture user may run any locale.
 */
const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' }

test.beforeEach(async ({ page }) => {
  await login(page, CREDS)
})

test('error banner carries a report-problem mailto and dedups on repeat', async ({ page }) => {
  // Bogus client id → loadClientDetail fails → notify(..., true) error banner.
  await page.goto('/apps/carecheck/#/client/999999999', { waitUntil: 'domcontentloaded' })

  const banner = page.locator('[data-cck-banner]')
  await expect(banner).toBeVisible()
  await expect(banner).toHaveAttribute('role', 'alert')

  const link = banner.locator('a.cck-nav-footer__toast-link')
  await expect(link).toHaveCount(1)
  const href = await link.getAttribute('href')
  expect(href).toMatch(/^mailto:/)
  expect(href).toContain('subject=')
  expect((await link.textContent()).trim().length).toBeGreaterThan(0)

  // The live-region announcement stays text-only — SRs must not hear the link.
  const alertRegion = page.locator('#cck-alert-region')
  await expect(alertRegion).not.toContainText('mailto')

  // Repeat error: replace-text wipe must leave exactly one banner + one link.
  await page.goto('/apps/carecheck/#/client/999999998', { waitUntil: 'domcontentloaded' })
  await expect(page.locator('[data-cck-banner]')).toHaveCount(1)
  await expect(banner.locator('a.cck-nav-footer__toast-link')).toHaveCount(1)
})
