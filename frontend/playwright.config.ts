import { defineConfig, devices } from '@playwright/test'

// End-to-end tests run against the Compose stack (roadmap M3-12, docs/10-quality/testing.md §End-to-end).
// global-setup.ts resets the demo data and signs each user in once; specs reuse the stored sessions.
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : 4,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI
    ? [['list'], ['junit', { outputFile: 'test-results/e2e.xml' }], ['html', { open: 'never' }]]
    : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://app.shp.localhost',
    // The development stack serves certificates from Caddy's internal CA (local-development.md).
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
