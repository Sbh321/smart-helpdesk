# Smart Helpdesk — backend

Laravel 13 on PHP 8.5, API-only, organised as a modular monolith. Architecture: [../docs/03-architecture/backend.md](../docs/03-architecture/backend.md). Decisions: [../docs/adr/](../docs/adr/README.md).

## Layout

```text
app/
├── Modules/<Module>/        one namespace per module, each with <Module>ServiceProvider
│   ├── Actions/  Contracts/  Strategies/  Domain/  Http/  Models/  Policies/
│   ├── Jobs/  Events/  Listeners/  Notifications/  Console/  Queries/
│   ├── Database/Migrations/ Factories/ Seeders/
│   └── Routes/api.php       mounted under /v1 by the module provider
├── Support/                 Clock, module base provider, job conventions, attributes
└── Providers/AppServiceProvider.php
config/helpdesk.php          hosts, strategy bindings, tenant-overridable defaults
routes/api.php               system routes (/v1/ping, /v1/health)
tests/{Unit,Feature,Architecture}
```

Modules: Platform, Tenancy, Identity, Contacts, Agents, Tickets, Sla, Automation, Media, Mail, Notifications, Integrations, Reporting, Audit. Allowed dependencies are listed in the architecture document and enforced by `tests/Architecture/ModulesTest.php`.

## Commands

PHP runs in containers. With the stack running (`just up`), use the `just` recipes from the repository root. Without it, use the helper that runs the PHP 8.5 image:

```sh
../infra/scripts/php composer install
../infra/scripts/php vendor/bin/pest                 # tests
../infra/scripts/php vendor/bin/pint --test          # style
../infra/scripts/php vendor/bin/phpstan analyse      # static analysis (Larastan, level 5)
../infra/scripts/php php artisan route:list --except-vendor
```

## Conventions (summary)

- `declare(strict_types=1)` everywhere; final classes by default.
- Controllers are thin; FormRequests validate and check permissions; JsonResources shape responses.
- Writes go through Actions; algorithms are called through their strategy contracts (ADR-0023).
- Time comes from `App\Support\Time\Clock`; tests use `FrozenClock`.
- Migrations run as the owner role (`DB_CONNECTION=pgsql_owner`); the application uses `helpdesk_app`, which is subject to row-level security.
