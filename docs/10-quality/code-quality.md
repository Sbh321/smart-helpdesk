# Code quality

Rules that CI enforces and reviewers check. Tooling versions: [01-research/versions.md](../01-research/versions.md).

## Backend (PHP 8.5, Laravel 13)

| Tool | Configuration | Policy |
|---|---|---|
| Pint 1.32 | `laravel` preset plus `declare_strict_types`, `ordered_imports`, `final_class` off (Eloquent models are not final) | `vendor/bin/pint --test` in CI; `pint` in the pre-commit hook |
| Larastan 3.12 | `phpstan.neon`: level 5 in M1, level 6 since the M2 exit review (2026-09-21); `paths: app, config, routes, database`; `--memory-limit=1G` | Baseline file allowed only for third-party stubs; no `@phpstan-ignore` without a comment naming the reason. One scoped exception: `missingType.iterableValue` for API resource `toArray()`, because Scramble infers the schema from the returned literal and a loose array docblock hides it |
| Pest arch | `tests/Architecture/*` | see below |
| `composer audit` | CI | fails on high/critical |

Conventions:

- `declare(strict_types=1)` in every file (Pint enforces).
- Classes are `final` unless designed for extension (models, base test case, exceptions). Actions, domain services, DTOs, queries, jobs and listeners are `final`.
- DTOs are `final readonly class` with named constructor `fromRequest()`/`fromArray()`; no setters.
- Backed string enums for every status/kind with `label()` and, where relevant, `canTransitionTo()`.
- No `env()` outside `config/`; no `DB::raw` outside `Queries/` and migrations; no `dd()`/`dump()`.
- Controllers contain no conditionals beyond `return`; business rules live in Actions/Domain.
- Every job declares `$tries`, `$backoff`, `$timeout` and uses `TenantAwareJob`.
- Every scheduled command uses `onOneServer()->withoutOverlapping()`.
- Docblocks only where the type system cannot express the shape (array shapes for JSONB).

Architecture tests (Pest `arch()`):

```php
arch('domain is framework-free')->expect('App\Modules\*\Domain')->not->toUse(['Illuminate\Database', 'Illuminate\Http', 'Illuminate\Support\Facades']);
arch('modules respect dependency rules')->expect('App\Modules\Contacts')->not->toUse(['App\Modules\Tickets', 'App\Modules\Automation']);
arch('tickets only depend on contacts, agents and media')->expect('App\Modules\Tickets')->not->toUse(['App\Modules\Automation', 'App\Modules\Sla', 'App\Modules\Integrations', 'App\Modules\Reporting', 'App\Modules\Mail']);
arch('reporting is read-only for other modules')->expect('App\Modules')->not->toUse('App\Modules\Reporting')->ignoring('App\Modules\Reporting');
arch('algorithms are used through their contracts')->expect('App\Modules')->not->toUse('App\Modules\Automation\Strategies')->ignoring(['App\Modules\Automation', 'App\Providers']);
arch('automation never imports integrations/notifications')->expect('App\Modules\Automation')->not->toUse(['App\Modules\Integrations', 'App\Modules\Notifications']);
arch('no env outside config')->expect('App')->not->toUse('env');
arch('actions are final and invokable')->expect('App\Modules\*\Actions')->toBeFinal()->toHaveMethod('__invoke');
arch('no debug helpers')->expect(['dd', 'dump', 'ray', 'var_dump'])->not->toBeUsed();
```

The allowed dependency matrix is the one in [03-architecture/backend.md](../03-architecture/backend.md); any change needs an ADR note.

## Frontend (TypeScript 7, React 19)

| Tool | Configuration highlights |
|---|---|
| Biome 2.5 | `recommended` + `style/useImportType`, `suspicious/noExplicitAny: error`, `correctness/useExhaustiveDependencies: error`, `noRestrictedImports` for boundaries, formatter with 2 spaces / single quotes / trailing commas; organise imports on save |
| `tsconfig.json` | `strict`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `noImplicitOverride`, `verbatimModuleSyntax`, `moduleResolution: bundler`, paths `@/*` |
| `tsc --noEmit` | CI gate |
| `pnpm audit --audit-level=high` | CI gate |

Conventions:

- No `any` in `features/`, `lib/`, `routes/`; `unknown` plus narrowing. Generated `schema.d.ts` is exempt.
- Import boundaries (`noRestrictedImports`): `routes → features/*/index, components, lib`; `features/x → components, lib, features/x/**` only; `components/shared → components/ui, lib`; `components/ui → lib/utils` only; nothing imports `routes`.
- All user-facing strings from `src/copy/en.ts`; `Intl` for numbers/dates via `lib/datetime`.
- Components: function components, props typed with `interface`, no default exports except route files.
- Query keys only through `lib/api/queryKeys.ts` factories (prefixed with `tenantId`).
- `data-testid` only where a role/label query is impossible.

## Commits, branches, review

- **Conventional Commits**: `feat(tickets): …`, `fix(sla): …`, `test(isolation): …`, `docs(adr): …`, `chore(infra): …`, `perf(queries): …`. Scope = module or area. Body explains why; footer references the roadmap task (`Task: M2-07`).
- **Branches**: `main` is always green and deployable to the demo environment. Work happens on short-lived `w2-07-ticket-comments` branches merged by fast-forward or squash; no long-lived branches; no direct pushes to `main` once CI exists (M1 day 2).
- **Self-review checklist** (solo developer, before merge): diff read top to bottom as a reviewer; task acceptance criteria ticked; [Definition of Done](definition-of-done.md) satisfied; no leftover `MVP-SHORTCUT` without a backlog item; no secrets; migrations reversible; docs pages named in the task updated; CI green; `just verify` run locally.

## Pre-commit hooks (lefthook)

```yaml
pre-commit:
  parallel: true
  commands:
    pint:   { root: backend/,  glob: "*.php", run: vendor/bin/pint {staged_files} }
    stan:   { root: backend/,  glob: "*.php", run: vendor/bin/phpstan analyse --no-progress {staged_files} }
    biome:  { root: frontend/, glob: "*.{ts,tsx,json,css}", run: pnpm biome check --write {staged_files} }
    types:  { root: frontend/, glob: "*.{ts,tsx}", run: pnpm tsc --noEmit }
    secrets: { run: gitleaks protect --staged }
commit-msg:
  commands:
    conventional: { run: npx --yes commitlint --edit {1} }
```

## MVP-SHORTCUT markers

Deliberate simplifications are marked in code exactly as:

```php
// MVP-SHORTCUT: exports run synchronously for < 1 000 rows; V1: V1-ANL-02 async export for all sizes
```

Rules: the marker names the reason and a V1 backlog item ID; `just shortcuts` greps them into `roadmap/09-v1-backlog.md` §"Cut from MVP during execution"; a shortcut without a backlog item fails the `docs:check` script; shortcuts are never used to skip authorisation, tenant scoping, validation or tests.

## Dependency policy

A new dependency needs a row in the relevant research page (reason, alternative already installed, overlap, lock-in, maintenance, classification) before `composer require`/`pnpm add`. Lockfiles are committed. Dependabot opens weekly PRs; majors are batched after the MVP. `composer audit` and `pnpm audit` run in CI; the licence inventory (`just licences`) is regenerated at the end of M3 for the report appendix.
