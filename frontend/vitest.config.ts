import { playwright } from '@vitest/browser-playwright'
import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config'

// Two projects: fast Node unit tests, and component tests in a real browser (ADR-0016).
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      projects: [
        {
          extends: true,
          test: {
            name: 'unit',
            environment: 'node',
            include: ['src/**/*.test.ts'],
          },
        },
        {
          extends: true,
          test: {
            name: 'browser',
            include: ['src/**/*.browser.test.tsx'],
            setupFiles: ['./src/test/setup-browser.ts'],
            browser: {
              enabled: true,
              headless: true,
              provider: playwright(),
              instances: [{ browser: 'chromium' }],
              // The agent app is desktop-first: 1280px is the design target (spacing.md), layouts below 768px are
              // V1 (accessibility.md). Vitest's 414px default squeezed the shell until header controls overlapped.
              viewport: { width: 1280, height: 800 },
            },
          },
        },
      ],
    },
  }),
)
