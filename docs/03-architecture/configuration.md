# Configuration strategy

Principle P7: configuration has layers; `.env` is not the settings database.

| Layer | Storage | Examples | Changed by | Read via |
|---|---|---|---|---|
| Application code | repo | status transitions, permission catalogue, default weights | developers | code |
| Environment | `.env` / Compose env / secrets | DB, Valkey, S3 endpoint, mail transport, `PLATFORM_DOMAIN`, `TENANCY_SINGLE_TENANT`, `APP_KEY`, Passport keys | operators (Ansible) | `config()` only (never `env()` outside config files) |
| Platform settings | `platform_settings` table (key/value JSONB) | default plan limits, signup enabled, feature defaults | platform admin UI | `PlatformSettings::get()` cached |
| Tenant settings | `tenant_settings` (one row per tenant, JSONB `data` + `version`) | branding, priority weights/thresholds, duplicate threshold, auto-close days, reopen window, first-response rule, feature flags | tenant admin UI | `Settings::get('automation.priority')` cached per tenant |
| User preferences | `users.preferences` JSONB | theme, density, table columns (server-side later) | user | `/me` |
| Feature flags | `tenant_settings.data.features` merged with platform defaults | `realtime`, `bulk_actions`, `exports` | tenant admin / platform | `Feature::enabled('realtime')` |

## Tenant settings schema

Validated with a PHP schema (Laravel validation rules per key) and versioned (`settings_version`); every algorithm result stores the strategy name and version and the settings version that produced it, for reproducibility and for comparing strategies later. Defaults live in code (`config/helpdesk.php`) and are merged under tenant overrides, so adding a setting never requires a data migration.

```php
// config/helpdesk.php (defaults)

// Platform-level: which implementation serves each algorithm contract (ADR-0023). Not a tenant setting.
'strategies' => [
  'priority'   => \App\Modules\Automation\Strategies\Baseline\BasicWeightedPriority::class,
  'assignment' => \App\Modules\Automation\Strategies\Baseline\LeastLoadedAgent::class,
  'duplicates' => \App\Modules\Automation\Strategies\Baseline\JaccardDuplicates::class,
  'sla'        => \App\Modules\Sla\Strategies\Baseline\SimpleSlaTimer::class,
],

// Tenant-overridable settings, namespaced per strategy so a replacement brings its own keys.
'automation' => [
  'priority'   => ['baseline' => ['weights' => ['impact' => 0.40, 'urgency' => 0.35, 'tier' => 0.15, 'age' => 0.10],
                                  'thresholds' => ['P1' => 75, 'P2' => 50, 'P3' => 25], 'age_full_hours' => 72]],
  'assignment' => ['enabled' => true],
  'duplicates' => ['baseline' => ['threshold' => 0.35, 'candidate_limit' => 50, 'window_days' => 30, 'max_suggestions' => 5]],
],
'tickets'  => ['auto_close_days' => 7, 'reopen_window_days' => 14],
'sla'      => ['warning_fraction' => 0.75],
'shifts'   => ['enforce' => false],
'features' => ['realtime' => false, 'exports' => true],
```

## Secrets

Only in env/secret files; never in settings tables; webhook secrets and client secrets encrypted at rest with `APP_KEY` (`encrypted` cast) or hashed where only verification is needed.
