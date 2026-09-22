import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { chromium, type FullConfig } from '@playwright/test'
import { appUrl, repoRoot, stateFor, type UserKey } from './support/env'
import { signIn } from './support/session'

/**
 * Once per run (docs/10-quality/testing.md §End-to-end):
 * 1. `demo:reset` replays the demo dataset, so every run starts from the same workspaces
 *    (about 35 s; `E2E_RESET=0` skips it when the data is known to be fresh, `E2E_RESET_COMMAND`
 *    replaces the command, e.g. for a stack that is not this checkout's Compose project).
 * 2. One sign-in per user through the form, saved as storage state. Specs reuse it instead of signing
 *    in again, which keeps the run under the login throttle (5 a minute per workspace and email).
 */
const signedIn: UserKey[] = ['meera', 'priya', 'sam']

export default async function globalSetup(config: FullConfig) {
  if (process.env.E2E_RESET !== '0') {
    const started = Date.now()
    const command = process.env.E2E_RESET_COMMAND ?? 'docker compose exec -T app php artisan demo:reset'
    try {
      // The container logs to stderr; its output is shown only when the reset fails.
      execFileSync('sh', ['-c', command], { cwd: repoRoot, stdio: 'pipe', maxBuffer: 64 * 1024 * 1024 })
    } catch (error) {
      const failed = error as { stdout?: Buffer; stderr?: Buffer }
      console.error(`${command} failed:\n${failed.stdout ?? ''}\n${failed.stderr ?? ''}`)
      throw error
    }
    console.log(`demo:reset finished in ${Math.round((Date.now() - started) / 1000)} s`)
  }

  mkdirSync(path.dirname(stateFor('meera')), { recursive: true })
  const use = config.projects[0]?.use ?? {}
  const browser = await chromium.launch()
  try {
    for (const user of signedIn) {
      const context = await browser.newContext({ baseURL: use.baseURL ?? appUrl, ignoreHTTPSErrors: true })
      const page = await context.newPage()
      await signIn(page, user)
      await context.storageState({ path: stateFor(user) })
      await context.close()
    }
  } finally {
    await browser.close()
  }
}
