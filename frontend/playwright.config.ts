import { defineConfig, devices } from '@playwright/test'

// End-to-end tests run against the Compose stack (roadmap M3-12).
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['junit', { outputFile: 'test-results/e2e.xml' }]] : 'list',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://app.shp.localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
