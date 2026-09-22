# Selected versions and compatibility

Verified 2026-09-17 against release pages, Packagist, npm and Docker Hub (see the ecosystem pages for URLs). Pin exactly in lockfiles; the constraint column is what goes in `composer.json` / `package.json` / images. Re-verify before milestone 1 day 1 and record changes here.

## Runtime and platform

| Component | Version at research | Constraint / image | Notes |
|---|---|---|---|
| PHP | 8.5.10 | `serversideup/php:8.5-fpm-nginx` (Debian trixie) | Active support to 2027-12; required by Pest 5 / PHPUnit 13 |
| Laravel | 13.32.0 | `^13.0` | Bug fixes to Q3 2027, security to 2028-03 |
| Node.js | 24.x (Active LTS) | `>=24 <25`; bump to 26 after 2026-10-28 | Vite 8 needs ≥ 20.19 |
| pnpm | 12.4.2 | `packageManager` field | |
| PostgreSQL | 18.6 | `postgres:18-trixie` | 19 is beta; `pgvector/pgvector:pg18` for V1 |
| Valkey | 9.1.2 | `valkey/valkey:9-alpine` | Redis 8.10 compatible alternative |
| RustFS | 1.0.0 | pinned exact tag | released 2026-09-16; Garage 2.4.1 alternative |
| Caddy | 2.11.4 | `caddy:2` | |
| Mailpit | 1.31.1 | `axllent/mailpit` | dev only |
| Stalwart Mail Server | 0.16.22 | `stalwartlabs/stalwart:v0.16.22-alpine` (M3-18) | profile `mail`; the Alpine variant is 80 MB against 103 MB; 0.16.23 was published on 2026-09-21 and is not taken mid-sprint; docker-mailserver 14.0.0 alternative |
| Stalwart CLI | 1.0.12 | `stalwartlabs/cli:1.0.12` (4 MB) | M3-18: `mail-init.sh` applies Stalwart's configuration with the CLI's declarative `apply` (0.16 manages everything through its JMAP API; the server image has no CLI); profile `mail-tools`, run only by the script |
| dkimpy | 1.1.8 | `uvx --from dkimpy==1.1.8` (dev tool, not installed) | M3-18: `infra/scripts/mail-dkim-verify.py` / `just mail-dkim-check` verify Stalwart's DKIM signature on a Mailpit message without public DNS |
| Docker Engine / Compose | 29.8 / v5.5 | Compose spec with `include`, profiles, `develop.watch` | local machine has 29.6 / v5.2 |
| OpenTofu | 1.12.6 | pinned via mise | Terraform 1.16 is BUSL |
| ansible-core | 2.21.4 | + community.docker 5.3, ansible.posix 2.2, community.general 13.4 | `geerlingguy.docker` role |
| mise / just | 2026.9 / 1.58 | | tool pinning + task runner |

### Deployment tooling (verified 2026-09-21, M3-14)

| Component | Version | Constraint | Notes |
|---|---|---|---|
| community.docker / ansible.posix / community.general | 5.3.0 / 2.2.2 / 13.4.0 | `>=5.3.0,<5.4.0` etc. in `infra/ansible/requirements.yml` | installed into `infra/ansible/collections` (gitignored) |
| geerlingguy.docker (Ansible role) | 8.0.0 | exact | Docker CE + Compose plugin from download.docker.com; installed into `infra/ansible/galaxy_roles` |
| ansible-lint | 26.8.0 | run with `uvx --from ansible-lint ansible-lint`, not installed | the `production` profile passes; CI check named in ansible.md |
| OpenTofu provider `digitalocean/digitalocean` | 2.101.1 | `~> 2.100` | reference environment |
| OpenTofu provider `hetznercloud/hcloud` | 1.69.0 | `~> 1.68` | `hcloud_zone` / `hcloud_zone_rrset` for DNS |
| OpenTofu provider `hashicorp/aws` | 6.65.0 | `~> 6.65` | AWS folder and the Hetzner bucket (S3 API) |
| OpenTofu provider `hashicorp/google` | 8.3.0 | `~> 8.3` | GCP folder; GCS through HMAC keys |
| OpenTofu provider `hashicorp/local` | 2.9.1 | `~> 2.9` | writes the generated Ansible inventory |
| OpenTofu provider `cloudflare/cloudflare` | 5.25.0 | `~> 5.25` | DNS records in the parent zone for the AWS environment (`providers/cloudflare/dns`); the owner's domain is on Cloudflare |

## Backend packages

| Package | Version | Constraint | Class |
|---|---|---|---|
| laravel/sanctum | 4.3.3 | `^4.3` | Required |
| laravel/passport | 13.8.0 | `^13.8` | Useful (time-boxed) |
| spatie/laravel-permission | 8.3.0 | `^8.3` | Required |
| stancl/tenancy | 3.10.1 | `^3.10` | Required |
| laravel/horizon | 5.49.0 | `^5.49` | Required |
| laravel/telescope | 5.24.0 | `^5.24` (dev) | Useful |
| laravel/reverb | 1.11.1 | `^1.11` | Should-have: installed in M3-16. It pulls `pusher/pusher-php-server` 7.3 (the broadcaster's publisher), `react/*` and `ratchet/rfc6455`. Reverb requires `guzzlehttp/psr7` ^2.6, so Composer moved `guzzlehttp/guzzle` from 8.2 to 7.15.5 (`psr7` 3.1 → 2.13, `promises` 3.0 → 2.5). Laravel 13, the AWS SDK and spatie/laravel-health accept both majors, and 7.x is still maintained. Revisit when Reverb allows psr7 3 |
| dedoc/scramble | 0.13.43 | `0.13.*` | Required |
| league/flysystem-aws-s3-v3 | 3.35.3 | `^3.35` | Required |
| spatie/laravel-health | 1.40.2 | `^1.40` | Required |
| spatie/laravel-backup | 10.3.3 | `^10.3` | Useful (on-prem) |
| opcodesio/log-viewer | 3.24.2 | `^3.24` | Useful (staff) |
| laravel/boost | 2.9.1 | dev | Useful (agents) |
| pestphp/pest | 5.2.1 | `^5.2` (dev) | Required |
| larastan/larastan | 3.12.1 | `^3.12` (dev) | Required |
| laravel/pint | 1.32.1 | `^1.32` (dev) | Required |
| intervention/image | 4.3.2 | `^4.3` | Required (media variants) |
| webklex/laravel-imap | 6.2.0 (with webklex/php-imap 6.2.0) | `^6.2` | Useful (inbound email); added in M3-19 for `mail:fetch-inbound` and MIME decoding (`Message::fromString`), pure PHP (no `ext-imap`) |
| openspout/openspout | 5.11.3 (PHP 8.4/8.5) | `^5.11` | Required (XLSX report export) |
| Deferred | pulse 1.8.1, pennant 1.26.0, octane 2.19.1, scout 11.7.0, activitylog 5.1.1, rector-laravel 2.6.2, spatie/laravel-medialibrary 11.23.8 | — | V1 / Rejected |

## Frontend packages

| Package | Version | Constraint | Class |
|---|---|---|---|
| react, react-dom | 19.3.0 | `^19.3` | Required |
| vite | 8.3.0 | `^8.3` | Required |
| @vitejs/plugin-react + oxc-transform-react | 6.1.1 / 0.145.x | `^6.1` / `~0.145.0` (peer range) | Required |
| typescript | 7.0.2 | `^7.0` | Required |
| tailwindcss, @tailwindcss/vite | 4.3.3 | `^4.3` | Required |
| shadcn (CLI) | 4.21.0 | dlx latest | Required |
| @base-ui/react | 1.8.0 | `^1.8` | Required |
| @tanstack/react-router, router-plugin | 1.170.38 / 1.168.40 | `^1.170` | Required (zod-adapter not used: it supports Zod 3 only; Zod 4 schemas are passed to `validateSearch` directly) |
| @tanstack/react-query | 5.103.1 | `^5.103` | Required |
| @tanstack/react-table | 9.2.4 | `^9.2` (fallback `^8`) | Required: v9 is published and used (M1-14, `useTable` + `tableFeatures`); the v8 fallback was not needed |
| @tanstack/react-form | 1.33.5 | `^1.33` | Required |
| zod | 4.6.5 | `^4.6` | Required |
| date-fns, @date-fns/tz | 4.4.0 / 1.5.0 | `^4.4` / `^1.5` | Required |
| recharts | 3.10.1 | `^3.10` | Required |
| lucide-react | 1.47.0 | `^1.47` | Required |
| cn, class-variance-authority | 0.3 / 0.7 | added by `shadcn init` | Required by shadcn components (`@fontsource-variable/geist`, also added by `shadcn init`, was removed in M1-11: the system font stack is used, see typography.md) |
| @vitest/browser-playwright | 5.0.1 | `^5.0` (dev) | Required (Vitest browser provider) |
| sonner | 2.0.8 | `^2.0` | Required (the generated `ui/sonner.tsx` reads the theme from `next-themes`; it was rewritten to use our own `useTheme()` and `next-themes` was uninstalled again — M1-12) |
| react-day-picker | 10.0.1 | `^10.0` | Useful: added in M1-14 by `shadcn add calendar` for the `DateRangeFilter` (ticket `filter[created_between]`, audit logs); the shadcn `calendar` for Base UI is written against it, and Base UI has no date picker of its own |
| openapi-typescript / openapi-fetch | 7.13.0 / 0.17.0 | run via `pnpm dlx` with TypeScript 5.9.3 / `^0.17` | Required (openapi-typescript needs the TypeScript 5 compiler API) |
| @biomejs/biome | 2.5.14 | `^2.5` (dev) | Required |
| vitest, @vitest/browser, vitest-browser-react | 5.0.1 / 5.0.1 / 2.3.0 | `^5.0` (dev) | Required |
| @playwright/test, @axe-core/playwright | 1.63.0 / 4.13.0 | `^1.63` / `^4.13` (dev) | Required |
| axe-core | 4.13.0 | `4.13.0` (dev, pinned) | Required: the Vitest browser project runs the same engine in-process (`src/test/accessibility.browser.test.tsx`), so accessibility regressions fail in `pnpm test:browser` rather than only in the slower E2E run. It is already present transitively through `@axe-core/playwright`; the direct entry pins both to one version so the two suites cannot report differently (M1-12) |
| msw | 2.15.0 | `^2.15` (dev) | Useful |
| culori (token contrast check script) | not installed | — | Not needed: `scripts/check-contrast.ts` uses the dependency-free `src/lib/theme/contrast.ts` (M1-11) |
| docx (report builder, `report/` only) | 9.7.1 | `^9.7` | Required for report generation; not part of the product |
| laravel-echo, @laravel/echo-react | 2.5.0 | `^2.5` | Should-have: installed in M3-16. `@laravel/echo-react` bundles Echo; `laravel-echo` supplies its type declarations |
| pusher-js | 8.6.0 | `^8.6` | Should-have (M3-16): required peer of `@laravel/echo-react` for the Reverb (Pusher protocol) connector; version as in [realtime-options.md](realtime-options.md) |
| Deferred | @tanstack/react-virtual 3.14, zustand 5.0, storybook 10.6, i18next 26 | — | V1 |

## Experiment tooling (report only)

Not part of the application or its images; used on the developer machine to draw the result-analysis plots ([12-academic/result-analysis-plan.md](../12-academic/result-analysis-plan.md)).

| Package | Version | Constraint | Class | Reason |
|---|---|---|---|---|
| matplotlib (Python 3.13, with numpy 2.5) | 3.11.2 | `==3.11.2` in `experiments/requirements.txt`, run through `uv run --with-requirements` | Required (report) | Plots 1–10 as PNG and SVG from the committed CSV results; the standard, scriptable plotting library, so `just reproduce` regenerates every figure without a browser. The SPA's Recharts is interactive and not suited to report figures; a spreadsheet would not be reproducible. |

## Compatibility notes

- PHP 8.5 is required by Pest 5 / PHPUnit 13; Laravel 13 supports 8.3–8.5.
- Vite 8's React plugin uses Oxc; Babel plugin config is invalid; React Compiler via `compiler: true`.
- TypeScript 7 lacks a stable programmatic API until 7.1; typescript-eslint is therefore not used (Biome).
- shadcn defaults to Base UI; copying Radix-era snippets is a hazard.
- TanStack Table v9 API differs from the v8 tutorials. Two traps found in M1-14: controlled slices passed in `state` must keep their identity between renders (`useTable` publishes a changed slice back into its store, so a new array on every render is a render loop), and row/header methods read state the React Compiler cannot see, so a component rendering rows from them opts out with `'use no memo'`.
- Vitest 5 browser matchers: `toHaveTextContent` compares the whole text exactly; `toMatchTextContent` is the substring/RegExp form.
- stancl/tenancy single-DB mode disables the database bootstrapper; Redis bootstrapper needs phpredis.
- Scramble introspects the DB: export after migrations.
- PG18 virtual generated columns cannot be indexed: use `STORED` for `search_vector`.
