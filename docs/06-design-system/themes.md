# Themes: light, dark, system

Three modes: `light`, `dark`, `system`. The stored preference is the *choice*; the applied theme is the *resolution* of that choice against `prefers-color-scheme`. Tokens per theme are in [tokens.md](tokens.md).

## Mechanism

- The applied theme is an attribute on `<html>`: `data-theme="light" | "dark"`. Never a class, never absent after boot.
- Tailwind's `dark:` variant targets the attribute: `@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));` (in `tokens.css`).
- `color-scheme: light|dark` is set on the same scopes so native controls, scrollbars and form widgets follow.
- Components never branch on theme in JS; they use tokens. The only JS that knows the theme is `ThemeProvider` and things that must pick an asset (logos).

## No-flash boot script

The boot script is inline in `frontend/index.html`, in `<head>` before any stylesheet or module. The attributes therefore exist at first paint:

```html
<script>
  (() => {
    let stored = null;
    let density = null;
    try {
      stored = window.localStorage.getItem('sh.theme');
      density = window.localStorage.getItem('sh.density');
    } catch {
      // Blocked storage: fall back to the system theme.
    }
    const choice = stored === 'light' || stored === 'dark' ? stored : 'system';
    const dark =
      choice === 'dark' ||
      (choice === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches === true);
    const root = document.documentElement;
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-choice', choice);
    root.setAttribute('data-density', density === 'compact' ? 'compact' : 'comfortable');
  })();
</script>
```

| Rule | Value |
|---|---|
| Storage keys | `sh.theme`, `sh.density` |
| Unknown or missing theme | `system` |
| Unknown or missing density | `comfortable` |
| No `matchMedia` | light |
| Mirrors | `parseThemeChoice`, `parseDensity`, `resolveTheme` in `src/lib/theme/theme.ts`; change both together |

`index.html` also sets `<meta name="color-scheme" content="light dark">`.

The script is meant to be allowed by the CSP through a hash (`script-src 'self' 'sha256-…'`), written into the Caddy config template ([security](../03-architecture/security.md)). The hash must change whenever the script changes. The hash generation step and the CSP header are not implemented yet.

## ThemeProvider

The theme code lives in `frontend/src/lib/theme/`. File names are kebab-case. No library is used.

| File | Contents |
|---|---|
| `theme.ts` | types, storage keys, attribute names, pure functions `parseThemeChoice`, `parseDensity`, `resolveTheme`, `themeAttributes` |
| `theme-dom.ts` | guarded `localStorage` read and write, `systemPrefersDark`, reading the initial state, `applyThemeAttributes` |
| `theme-context.ts` | `ThemeContext` and `useTheme()` |
| `theme-provider.tsx` | `ThemeProvider` |
| `contrast.ts` | WCAG contrast for oklch colours ([tokens](tokens.md) §Contrast pairs to verify) |
| `index.ts` | public exports |

`ThemeProvider` wraps the app in `src/app/main.tsx`.

```ts
type ThemeChoice = 'light' | 'dark' | 'system';
type ResolvedTheme = 'light' | 'dark';
type Density = 'comfortable' | 'compact';

interface ThemeContextValue {
  theme: ThemeChoice;            // the stored choice
  resolvedTheme: ResolvedTheme;  // the theme applied now
  setTheme: (next: ThemeChoice) => void;
  density: Density;
  setDensity: (next: Density) => void;
}
```

`<html>` carries three attributes:

| Attribute | Meaning |
|---|---|
| `data-theme` | the applied theme, `light` or `dark` |
| `data-theme-choice` | the stored choice, `light`, `dark` or `system` |
| `data-density` | `comfortable` or `compact` |

Behaviour:

1. Initial state is read from the attributes the boot script set. If the script did not run (tests), it falls back to storage. The first render does not change the page.
2. `setTheme` writes `localStorage['sh.theme']` and updates state. An effect then writes all three attributes, touching only the ones that changed. `setDensity` works the same way with `sh.density`.
3. In `system` mode the provider subscribes to `matchMedia('(prefers-color-scheme: dark)')` change events and re-resolves live. The listener is removed when the choice becomes explicit.
4. Cross-tab consistency: a `storage` event listener applies theme and density changes made in another tab.
5. Server sync is not implemented. `MVP-SHORTCUT: preference is local only; V1: sync with /v1/me preferences.` The planned V1 behaviour: when the session loads (`/v1/me`) and `user.preferences.theme` differs from local storage, the server value wins once and is written locally; `setTheme` also PATCHes `/v1/me/preferences` when logged in (fire-and-forget, debounced).
6. `useTheme()` is the only consumer API. It throws outside `<ThemeProvider>`.

Storage reads and writes are wrapped in `try/catch`. A failed read counts as "no stored value", so the theme falls back to `system`. A failed write is ignored, so a choice lasts for the current page only.

## ThemeToggle

`src/components/shared/theme-toggle.tsx` renders a Light / Dark / System segmented control. It is meant for the topbar and settings; today it appears on the home placeholder.

| Aspect | Implementation |
|---|---|
| Group | `<fieldset>` with a visually hidden `<legend>` from `copy.theme.label` |
| Options | three shadcn `Button`s with an icon (Sun, Moon, Monitor) and a visible label |
| Selected state | `aria-pressed="true"`, `outline` variant with a `border-input` border so the state indicator reaches 3:1 (WCAG 1.4.11) |
| Announcement | a visually hidden `role="status"` `aria-live="polite"` region; for `system` it names the resolved theme (`copy.theme.announcement.systemDark` / `systemLight`) |

## Charts

Recharts renders SVG, so `fill`/`stroke` values are CSS variables. shadcn's `ChartContainer` turns a `ChartConfig` (`{ open: { label: 'Open', color: 'var(--color-status-open-foreground)' } }`) into per-chart `--color-<key>` variables. Because the variables re-resolve on theme change, charts switch instantly without re-rendering data. Tooltip and legend come from shadcn `ChartTooltip`/`ChartLegend` and use `--surface-elevated`. Series order uses `--chart-1..6`; status/priority charts use the matching semantic tokens so the bar for "breached" is the same colour as the badge.

## Images and logos

- The platform logo ships as two SVGs: `logo-light.svg` and `logo-dark.svg`; `<Logo />` picks by `resolvedTheme`. A single SVG using `currentColor` is preferred where possible so no branching is needed.
- Tenant logos: `branding.logo_url` and optional `branding.logo_dark_url`; if the dark variant is missing the light one is placed on a `--surface-elevated` tile with padding.
- Raster illustrations for empty states use the `<picture>` element with `media="(prefers-color-scheme: dark)"` sources plus an explicit override when the choice is not `system` (the provider swaps `src` in that case).
- Attachments previews are never themed.

## Density

Same provider, attribute `data-density`; see [tokens.md](tokens.md) §Density and [spacing.md](spacing.md).

## Tenant branding (built in M2-01)

`ThemeProvider` receives `tenant.branding` from the session and injects a `<style id="tenant-brand">` element with the overrides described in [tokens.md](tokens.md) §Tenant branding, scoped to `:root[data-tenant]`. The overrides are theme-aware: the tenant supplies one primary colour, and the provider derives a lighter variant for dark mode by raising oklch lightness to ≥ 0.70 so contrast on dark surfaces holds. Nothing else in the app changes.

As built (M2-01): `lib/theme/brand.ts` builds the CSS (`tenantBrandCss`) from a `#rrggbb` primary (MVP-SHORTCUT: hex only, as the API validates); `applyTenantBrand` writes it and `data-tenant`, and removes both when the workspace has no colour. The dark rule also matches a nested `[data-theme="dark"]` island. The Branding page previews an unsaved colour through `previewTenantPrimary` and ends the preview when it unmounts. The sidebar shows the workspace logo (`TenantLogo`): the dark variant in the dark theme, otherwise the light logo on a `--surface-elevated` tile, and the product mark when there is no logo or it fails to load.

## Tests

| Level | Test | Status |
|---|---|---|
| Unit (Vitest node) | `theme.test.ts`: `resolveTheme` truth table, `parseThemeChoice` and `parseDensity` fallbacks, `themeAttributes` | done |
| Unit (Vitest node) | `contrast.test.ts`: oklch parsing, conversion to sRGB, gamut clipping, WCAG ratio, `pickForeground` | done |
| Token check | `pnpm tokens:check`: 104 contrast checks | done |
| Component (Vitest browser) | `theme-toggle.browser.test.tsx`: switching updates `data-theme`, `data-theme-choice` and storage, and announces the change; system mode follows a mocked `matchMedia` change and the listener is dropped for an explicit choice; initial state comes from the boot-script attributes; blocked storage still switches the theme | done |
| E2E (Playwright) | `theme-persistence.spec.ts`: set dark → reload → `html[data-theme=dark]` present before hydration (assert via `page.evaluate` on `DOMContentLoaded`); system mode with `emulateMedia({ colorScheme: 'dark' })`; no-flash check by screenshotting the first frame with `page.route` delaying the JS bundle | M3-12 |
| E2E (axe) | axe scan of dashboard and ticket page in both themes | M3-12 |
