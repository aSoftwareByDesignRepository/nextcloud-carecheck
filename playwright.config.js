import { defineConfig, devices } from '@playwright/test'

const baseURL = process.env.NC_BASE_URL || 'http://localhost:8081'

export default defineConfig({
  testDir: 'e2e',
  globalTeardown: './e2e/global-teardown.mjs',

  globalSetup: './e2e/global-setup.mjs',

  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  use: {
    baseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
})
