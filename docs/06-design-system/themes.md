# Themes: light, dark, system

Three modes: `light`, `dark`, `system`. The stored preference is the *choice*; the applied theme is the *resolution* of that choice against `prefers-color-scheme`. Tokens per theme are in [tokens.md](tokens.md).

## Mechanism

- The applied theme is an attribute on `<html>`: `data-theme="light" | "dark"`. Never a class, never absent after boot.
- Tailwind's `dark:` variant targets the attribute: `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));` (in `tokens.css`).
- `color-scheme: light|dark` is set on the same scopes so native controls, scrollbars and form widgets follow.
- Components never branch on theme in JS; they use tokens. The only JS that knows the theme is `ThemeProvider` and things that must pick an asset (logos).

## No-flash boot script

Inline in `frontend/index.html`, before any stylesheet or module, so the attribute exists at first paint:

```html
<script>
  (function () {
    var KEY = 'sh.theme', DKEY = 'sh.density';
    var stored = null, density = 'comfortable';
    try { stored = localStorage.getItem(KEY); density = localStorage.getItem(DKEY) || density; } catch (e) {}
    var choice = stored === 'light' || stored === 'dark' ? stored : 'system';
    var dark = choice === 'dark' || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var root = document.documentElement;
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-choice', choice);
    root.setAttribute('data-density', density === 'compact' ? 'compact' : 'comfortable');
  })();
</script>
```

The script is allowed by the CSP through a hash (`script-src 'self' 'sha256-…'`), generated at build by a Vite plugin step and written into the Caddy config template ([security](../03-architecture/security.md)).

## ThemeProvider

`src/lib/theme/ThemeProvider.tsx` (about 60 lines, no library):

```ts
type ThemeChoice = 'light' | 'dark' | 'system';
type Resolved = 'light' | 'dark';
type Density = 'comfortable' | 'compact';

interface ThemeContextValue {
  theme: ThemeChoice;          // the stored choice
  resolvedTheme: Resolved;     // what is applied now
  setTheme(next: ThemeChoice): void;
  density: Density;
  setDensity(next: Density): void;
}
```

Behaviour:

1. Initial state is read from the attributes the boot script set (single source of truth; no re-resolution on mount, so no flicker).
2. `setTheme` writes `localStorage['sh.theme']`, updates `data-theme-choice`, resolves and updates `data-theme`.
3. In `system` mode the provider subscribes to `matchMedia('(prefers-color-scheme: dark)').addEventListener('change', …)` and re-resolves live; the listener is removed when the choice becomes explicit.
4. Cross-tab consistency: a `storage` event listener applies changes made in another tab.
5. When the session loads (`/v1/me`), if `user.preferences.theme` exists and differs from local storage, the server value wins once and is written locally; `setTheme` also PATCHes `/v1/me/preferences` when logged in (fire-and-forget, debounced). Logged-out pages use local storage only.
6. `useTheme()` is the only consumer API. `ThemeToggle` (topbar and settings) renders a three-option segmented control: Light / Dark / System, with the resolved theme announced via `aria-live="polite"` on change.

Reads and writes to storage are wrapped in `try/catch`; a blocked storage falls back to `system` for the session.

## Charts

Recharts renders SVG, so `fill`/`stroke` values are CSS variables. shadcn's `ChartContainer` turns a `ChartConfig` (`{ open: { label: 'Open', color: 'var(--color-status-open-foreground)' } }`) into per-chart `--color-<key>` variables. Because the variables re-resolve on theme change, charts switch instantly without re-rendering data. Tooltip and legend come from shadcn `ChartTooltip`/`ChartLegend` and use `--surface-elevated`. Series order uses `--chart-1..6`; status/priority charts use the matching semantic tokens so the bar for "breached" is the same colour as the badge.

## Images and logos

- The platform logo ships as two SVGs: `logo-light.svg` and `logo-dark.svg`; `<Logo />` picks by `resolvedTheme`. A single SVG using `currentColor` is preferred where possible so no branching is needed.
- Tenant logos: `branding.logo_url` and optional `branding.logo_dark_url`; if the dark variant is missing the light one is placed on a `--surface-elevated` tile with padding.
- Raster illustrations for empty states use the `<picture>` element with `media="(prefers-color-scheme: dark)"` sources plus an explicit override when the choice is not `system` (the provider swaps `src` in that case).
- Attachments previews are never themed.

## Density

Same provider, attribute `data-density`; see [tokens.md](tokens.md) §Density and [spacing.md](spacing.md).

## Tenant branding (future)

`ThemeProvider` receives `tenant.branding` from the session and injects a `<style id="tenant-brand">` element with the overrides described in [tokens.md](tokens.md) §Tenant branding, scoped to `:root[data-tenant]`. The overrides are theme-aware: the tenant supplies one primary colour, and the provider derives a lighter variant for dark mode by raising oklch lightness to ≥ 0.70 so contrast on dark surfaces holds. Nothing else in the app changes.

## Tests

| Level | Test |
|---|---|
| Unit (Vitest node) | `resolveTheme(choice, prefersDark)` truth table; foreground computation for tenant primary |
| Component (Vitest browser) | `ThemeToggle` updates `data-theme`, persists to storage, announces change; system mode reacts to a mocked `matchMedia` change |
| E2E (Playwright) | `theme-persistence.spec.ts`: set dark → reload → `html[data-theme=dark]` present before hydration (assert via `page.evaluate` on `DOMContentLoaded`); system mode with `emulateMedia({ colorScheme: 'dark' })`; no-flash check by screenshotting the first frame with `page.route` delaying the JS bundle |
| E2E (axe) | axe scan of dashboard and ticket page in both themes |
