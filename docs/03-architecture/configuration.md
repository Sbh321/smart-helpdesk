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

### As built (M2-01)

- **Owner.** The `Tenancy` module owns storage and the API: `Settings\Settings` (`get('tickets.reopen_window_days')`, `section()`, `all()`, `version()`, `update()`), `SettingsRegistry`, the `TenantSetting` model (`data` JSONB, `version`). Outside a workspace every read answers the code defaults and version 0.
- **Sections are registered by the module that owns the behaviour**, in its service provider, so Tenancy imports none of them: `general`, `branding`, `features` (Tenancy); `automation.priority`, `automation.assignment`, `automation.duplicates` (Automation); `tickets` (Tickets); `sla` (Sla); `shifts` (Agents); `email` (Mail). A section is a `SettingsSection`: key, defaults, Laravel rules and a cross-field `check()`. `ArraySection::fromConfig()` takes the defaults from `config('helpdesk.<key>')`, limited to the keys that have a rule.
- **`general` lives on `tenants`** (`name`, `timezone`) because tenancy reads them before any settings exist; the write still bumps the version and is audited (`StoresOutsideSettings`).
- **API.** `GET /v1/settings`, `GET /v1/settings/{section}`, `PATCH /v1/settings/{section}` with `settings.manage`. PATCH is partial: submitted keys are merged into the section and the whole section is validated. Malformed values answer 422 `validation_failed`; values that do not fit together (weights do not sum to 1, thresholds not decreasing, an unknown key) answer 422 `settings_invalid`. Both carry `errors` per field. The response has `values` (effective) and `defaults`.
- **Version.** Every write adds one to `tenant_settings.version`. Priority results store it as `priority_settings_version`, assignments as `settings_version`. `GET /v1/me` carries `tenant.settings_version`, `tenant.branding` (`primary`, `logo_url`, `logo_dark_url`) and `tenant.features`.
- **Audit.** `settings.updated` with the section, its old and new values and the new version.
- **Cache.** One key per workspace (the tenancy cache bootstrapper tags it), forgotten on write.
- **Strategies follow the workspace.** The container binds `PriorityStrategy` and `DuplicateStrategy` to `WorkspacePriorityStrategy` / `WorkspaceDuplicateStrategy`, which build the configured strategy with the current workspace's settings on each call and rebuild only when the workspace or the settings version changes. This matters for commands that walk several workspaces with one resolved action (`tickets:reevaluate-priority`).
- **Branding.** `primary` is `#rrggbb` (MVP-SHORTCUT: no `oklch()` yet) and must allow 4.5:1 text; logos are ready image media items of the workspace. `POST /v1/media/intent` takes `purpose: branding` (needs `settings.manage`) and starts the file in the Branding folder.
- **`tickets.auto_close_days`** is applied by `tickets:auto-close` (daily 03:10, [scheduler.md](../11-operations/scheduler.md)): `AutoCloseTicket` closes resolved tickets as the `system` actor, per workspace with its own period; a ticket that changed since it was selected is left alone.
- **`sla.first_response_applies_to_agent_created = false`**: a ticket created in the UI gets no first-response timer (it is not started, rather than started and cancelled).
- **Email section (M3-18).** `email.sender_name` is registered by the `Mail` module and edited through its own endpoint `GET/PATCH /v1/settings/email` (`mail.manage`), which also shows the intake address and the DNS records ([email.md](../04-domain/email.md#settings--email-tenant)); the generic `/settings/email` routes are shadowed by it. M3-19 added `email.create_contacts` and `email.match_organisation_domain` (booleans, default true: unknown senders to the intake address become contacts, joined to the organisation of their domain) and the platform settings `helpdesk.mail.inbound.*` (`MAIL_INBOUND_ENABLED` default false, `MAIL_INBOUND_HOST`/`PORT`/`ENCRYPTION`/`USERNAME`/`PASSWORD`, `MAIL_INBOUND_FOLDERS` default `INBOX,Junk Mail`; batch 50, 30 MiB message limit, default impact 1 and urgency 2 for email tickets).
- **Not built here:** `platform_settings`, `Feature::enabled()` helper (features are read with `Settings::get('features.realtime')`).

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
'sla'      => ['warning_fraction' => 0.75, 'first_response_applies_to_agent_created' => true],
'shifts'   => ['enforce' => false],
'features' => ['realtime' => (bool) env('REALTIME_ENABLED', false), 'exports' => true], // realtime follows the deployment (M3-16)
```

## Secrets

Only in env/secret files; never in settings tables; webhook secrets and client secrets encrypted at rest with `APP_KEY` (`encrypted` cast) or hashed where only verification is needed.
