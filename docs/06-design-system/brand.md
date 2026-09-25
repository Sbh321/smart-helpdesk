# Brand mark and icons

One mark for the product everywhere: the lifebuoy (lucide `life-buoy`) in white on a rounded tile of the product blue (`--primary` of the light theme, `#146bdc`). It replaced the headset favicon on 2026-09-25, so the tab icon and the mark inside the apps are the same picture.

## Where it appears

| Surface | Mark |
|---|---|
| Browser tab, bookmarks and home screen of every host (landing, `app`, `admin`, `docs`, `platform-docs`, `api`, `monitor`) | `favicon.svg`, `favicon.ico` (16, 32, 48 px) and `apple-touch-icon.png` (180 px, full bleed) |
| Landing site header and footer, sign-in and workspace screens | `BrandMark` with the product name |
| Workspace sidebar when the workspace has no logo (or its logo fails to load) | `BrandMark` tile alone (`TenantLogo`) |
| Platform console header | `BrandMark` tile beside "Platform administration" |
| Platform documentation site | the VitePress logo (`favicon.svg`) |
| API reference (Scramble) | `logo: /favicon.svg` in `config/scramble.php` |
| Monitoring start page | the tile before its heading |

A workspace's own logo (Branding settings) replaces the tile in its sidebar only; the product mark stays everywhere else. In the apps the tile uses `bg-primary`, so in a workspace with its own primary colour it takes that colour.

## One source

`frontend/scripts/brand-icons.mjs` holds the mark (the tile, the colour and the lucide paths) and writes every icon file into the four folders the hosts serve their root files from: `frontend/public` (workspace app and platform console), `frontend/landing/public`, `docs/public` and `backend/public` (API, API reference and monitoring). Change the mark there and run it again (`node scripts/brand-icons.mjs` in `frontend/`); `BrandMark` (`components/shared/brand-mark.tsx`) draws the same lifebuoy with the lucide component. The favicon's stroke is heavier than the in-app icon's so it still reads at 16 px.

Horizon, Telescope and the health dashboard keep their vendors' pages; they are platform tools behind the platform sign-in, not product surfaces.
