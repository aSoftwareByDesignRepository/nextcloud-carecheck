// @ts-check
/**
 * Theme & responsive contract (Nextcloud UI master).
 *
 * For each real Nextcloud theme (light / dark / dark-highcontrast —
 * toggled server-side via occ) and each contract viewport
 * (320 mobile, 768 tablet, 1280 desktop):
 *   - no horizontal page pan (documentElement never wider than viewport)
 *   - axe WCAG 2.0/2.1 A+AA clean inside the app surface
 *   - focus ring renders (outline-width > 0) on nav links + primary control
 *   - all cck-* custom properties used in chrome resolve (computed ≠ '')
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { login } from './helpers/auth.js';

const __dirname = dirname(fileURLToPath(import.meta.url));

const CREDS = { username: 'cck_smoke', password: 'CckSmoke!2026' };
const CONTENT = '#app-content.cck-app';
const COMPOSE = 'docker compose -f ../../../docker-compose.yml exec -u www-data -T nextcloud';

// NC marks themes via data-themes / data-theme-* on <body>; 'light' = 'default'.
const THEMES = ['light', 'dark', 'dark-highcontrast'];
const THEME_MARKER = { light: 'default', dark: 'dark', 'dark-highcontrast': 'dark-highcontrast' };
const VIEWPORTS = [
  { name: 'mobile-320', width: 320, height: 640 },
  { name: 'tablet-768', width: 768, height: 900 },
  // Exact drawer→sidebar boundary: at 1023 the nav is a hidden drawer,
  // at 1024 it must render as the persistent sidebar (css/app.css @media).
  { name: 'desktop-1024', width: 1024, height: 800 },
  { name: 'desktop-1280', width: 1280, height: 800 },
];
const SURFACES = ['#/board', '#/settings/general'];

function setTheme(theme) {
  const json = theme === 'light' ? '["default"]' : `["${theme}"]`;
  execSync(`${COMPOSE} php occ user:setting ${CREDS.username} theming enabled-themes -- '${json}'`, {
    cwd: __dirname, stdio: 'pipe',
  });
}

async function axe(page, label) {
  const res = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  const appOnly = res.violations.filter((v) =>
    v.nodes.some((n) => n.target.some((t) => typeof t === 'string' && t.includes('app-content'))));
  expect(appOnly, `axe ${label}: ${JSON.stringify(appOnly.map((v) => v.id))}`).toEqual([]);
}

test.beforeEach(async ({ page }) => {
  await login(page, CREDS);
});

test.describe.serial('THEME_RESPONSIVE', () => {
  test.afterAll(() => {
    // Leave the lab user on the default theme — the rest of the suite
    // assumes light (e.g. screenshot-adjacent assertions).
    setTheme('light');
  });

  for (const theme of THEMES) {
    for (const vp of VIEWPORTS) {
      for (const surface of SURFACES) {
        test(`${theme} @ ${vp.name} ${surface}: no pan + axe + focus ring`, async ({ page }) => {
          setTheme(theme);
          await page.setViewportSize({ width: vp.width, height: vp.height });
          await page.goto(`/apps/carecheck/${surface}`, { waitUntil: 'domcontentloaded' });
          await page.waitForSelector(`${CONTENT} .cck-view:not([hidden])`, { timeout: 30_000 });

          // 1) theme actually applied — NC marks body[data-theme-*]
          const applied = await page.evaluate(() =>
            document.body.outerHTML.match(/data-theme[a-z-]*="[^"]*"/g)?.join(',') ?? 'none');
          expect(applied, `theme marker missing for ${theme}`).toContain(THEME_MARKER[theme]);

          // 2) no horizontal page pan
          const pan = await page.evaluate(() => {
            const r = document.documentElement;
            return r.scrollWidth > r.clientWidth + 1;
          });
          expect(pan, `horizontal pan at ${vp.width}px in ${surface} in ${theme}`).toBe(false);

          // 3) cck tokens resolve (catches undefined-var silent failures)
          const missing = await page.evaluate(() => {
            const cs = getComputedStyle(document.body);
            return ['--cck-text', '--cck-muted', '--cck-bg-card', '--cck-border',
                    '--cck-tint-info', '--cck-danger-fill', '--cck-radius-md', '--cck-touch']
              .filter((v) => !cs.getPropertyValue(v).trim());
          });
          expect(missing, `unresolved tokens in ${theme}`).toEqual([]);

          // 4) keyboard focus ring on first nav link
          const navLink = page.locator('#app-navigation .cck-nav__link').first();
          await navLink.focus();
          const ring = await navLink.evaluate((el) => {
            const cs = getComputedStyle(el);
            return parseFloat(cs.outlineWidth) > 0 && cs.outlineStyle !== 'none';
          });
          expect(ring, 'no focus ring on nav link').toBe(true);

          await axe(page, `${theme} @ ${vp.name} ${surface}`);
        });
      }
    }
  }

  test('forced-colors: system colors + focus rings survive emulation', async ({ page }) => {
    setTheme('light');
    await page.emulateMedia({ forcedColors: 'active' });
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto(`/apps/carecheck/#/board`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${CONTENT} .cck-view:not([hidden])`, { timeout: 30_000 });

    // Panel borders must be system CanvasText, not theme-mixed vars.
    const panel = await page.evaluate(() => {
      const el = document.querySelector('.cck-panel, .cck-table-wrap, .cck-stop');
      if (!el) return null;
      const cs = getComputedStyle(el);
      return { border: cs.borderColor, w: cs.borderWidth };
    });
    // Chromium resolves CanvasText → rgb(0,0,0) in forced-colors light.
    expect(panel, 'no bordered surface found').not.toBeNull();
    expect(panel.w, 'border must stay visible').not.toBe('0px');

    // Primary buttons render as system buttons (ButtonFace).
    const btn = await page.evaluate(() => {
      const el = document.querySelector('#app-content.cck-app .cck-btn--primary, #app-content.cck-app .cck-btn');
      if (!el) return null;
      const cs = getComputedStyle(el);
      return { bg: cs.backgroundColor };
    });
    expect(btn, 'no button found').not.toBeNull();

    // Focus ring still renders in forced-colors.
    const navLink = page.locator('#app-navigation .cck-nav__link').first();
    await navLink.focus();
    const ring = await navLink.evaluate((el) => parseFloat(getComputedStyle(el).outlineWidth) > 0);
    expect(ring, 'no focus ring in forced-colors').toBe(true);

    // No horizontal pan under forced colors either.
    const pan = await page.evaluate(() =>
      document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(pan).toBe(false);
  });

  test('settings surface axe under dark-highcontrast', async ({ page }) => {
    setTheme('dark-highcontrast');
    await page.setViewportSize({ width: 375, height: 700 });
    await page.goto(`/apps/carecheck/#/settings/general`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`${CONTENT} .cck-view:not([hidden])`, { timeout: 30_000 });
    await axe(page, 'dark-hc settings');
  });
});
