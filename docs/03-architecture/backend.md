# Backend architecture

Laravel 13, PHP 8.5, API-only. Decision: [ADR-0004](../adr/0004-modular-monolith.md). Conventions here are binding for every roadmap task.

## Module map

| Module | Owns | Public surface (what others may use) |
|---|---|---|
| `Platform` | `tenants` CRUD for super admins, platform users, tenant provisioning (`ProvisionTenant` action seeds defaults) | events `TenantProvisioned`, `TenantSuspended` |
| `Tenancy` | tenant resolution, bootstrappers (RLS setting, permission team, prefixes), `tenant_settings` (JSONB, schema-validated), `Settings` facade | `Tenancy::current()`, `Settings::get('priority.weights')` |
| `Identity` | users, invitations, sessions/auth controllers, roles/permissions (spatie), password reset | `User` model, `can` checks, events `UserInvited`, `RoleChanged` |
| `Contacts` | contacts, organisations, tags (polymorphic `taggables` live here) | models, `FindOrCreateContact` action |
| `Agents` | agent profiles, teams, skills, availability, workload counters | `AgentProfile`, `Team`, `Skill` models, `AgentDirectory` query (eligible agents) |
| `Tickets` | tickets, numbers, categories, comments, attachments, ticket events (history), status machine | actions `CreateTicket`, `AddComment`, `TransitionTicket`, `AssignTicket`, `OverridePriority`, `MarkDuplicate`; events `TicketCreated`, `TicketUpdated`, `TicketAssigned`, `TicketStatusChanged`, `TicketPriorityChanged`, `CommentAdded` |
| `Sla` | policies, targets, timers, calendars, `SlaStrategy` contract and `SimpleSlaTimer` baseline, `Clock`, `BusinessCalendar`, check command, warning/breach notifications | `SlaStrategy` (start/pause/resume/complete/recompute/check); events `SlaWarning`, `SlaBreached`, `SlaMet` |
| `Automation` | strategy contracts (`PriorityStrategy`, `AssignmentStrategy`, `DuplicateStrategy`), baseline implementations in `Strategies/Baseline` (academic, replaceable — [ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)), candidate loaders, settings schemas, experiments | the contracts, resolved from `config('helpdesk.strategies')`; results carry an explanation with strategy name and version |
| `Notifications` | notification classes, `notifications` table with `tenant_id`, in-app API | listeners on domain events |
| `Integrations` | Passport client management, webhook subscriptions/deliveries, signing, retry job, `/v1` public resources reuse Tickets/Contacts | listeners on domain events; `DeliverWebhook` job |
| `Reporting` (replaces `Analytics`) | change-capture trigger migration and actor settings, `report_*` read models, `ReportDefinition` catalogue, `ReportRunner`, entity overview queries, history/as-of API, CSV/XLSX export jobs, dashboard | `ReportRunner::run()`, `ChangeReplayer::asOf()`, `IntervalBuilder`, `reports:*` commands ([reporting.md](../04-domain/reporting.md)) |
| `Audit` | `audit_logs`, `RecordAuditLog` action, viewer API | `Audit::record(...)` |
| `Media` | media items, folders, mediables, presigned upload intent/complete, variants job, quota | `MediaItem`, `AttachMedia` action, `RegisterUpload` action ([media.md](../04-domain/media.md)) |
| `Mail` | outbound mail identity/headers, inbound fetch, `ReplyParser`, routing, `inbound_emails` | `mail:fetch-inbound`, events `InboundEmailProcessed` ([email.md](../04-domain/email.md)) |
| `Calendars` (inside `Sla`) | business calendars, holidays, `WorkingHoursCalendar`; agent shifts live in `Agents` | `BusinessCalendar` implementations ([ADR-0020](../adr/0020-business-calendars.md)) |

`app/Support`: `Clock` interface + `SystemClock`/`FrozenClock`, `TenantAwareJob` trait (tags, tenant context), `ApiResponse` helpers, `ProblemDetails` exception renderer, base `Action` marker, `Money`/`Duration` value objects if needed.

## Dependency rules (ADR-0004 plus later modules)

ADR-0004 fixed the original rules; the modules added by ADR-0018, ADR-0019 and ADR-0020 extend them without changing the principle:

| Module | May depend on |
|---|---|
| `Tickets` | `Contacts`, `Agents`, `Media` |
| `Automation` | strategy contracts (`PriorityStrategy`, `AssignmentStrategy`, `DuplicateStrategy`), baseline implementations in `Strategies/Baseline` (academic, replaceable — [ADR-0023](../adr/0023-minimal-replaceable-algorithms.md)), candidate loaders, settings schemas, experiments | the contracts, resolved from `config('helpdesk.strategies')`; results carry an explanation with strategy name and version |
| `Sla` (incl. calendars) | `Tickets`, `Tenancy` |
| `Media` | `Tenancy` |
| `Mail` | `Tickets`, `Contacts`, `Media` |
| `Notifications`, `Integrations`, `Reporting`, `Audit` | listeners/readers only; never imported by domain modules |

The Pest architecture test encodes this table.

## Folder layout of a module

```text
app/Modules/Tickets/
├── Actions/            CreateTicket.php, AddComment.php, TransitionTicket.php, AssignTicket.php
├── Console/            AutoCloseResolvedTickets.php
├── Database/
│   ├── Factories/
│   ├── Migrations/
│   └── Seeders/
├── Domain/             TicketStatus.php (enum + transitions), Priority.php (enum), Visibility.php, Exceptions/InvalidTransition.php
├── Events/             TicketCreated.php ...
├── Http/
│   ├── Controllers/    TicketController.php, TicketCommentController.php, TicketAttachmentController.php
│   ├── Requests/       StoreTicketRequest.php, TransitionTicketRequest.php ...
│   ├── Resources/      TicketResource.php, TicketSummaryResource.php, CommentResource.php
│   └── Middleware/
├── Jobs/
├── Listeners/
├── Models/             Ticket.php, TicketComment.php, TicketAttachment.php, TicketEvent.php, Category.php
├── Policies/           TicketPolicy.php
├── Queries/            TicketListQuery.php (filters, sort, search, pagination)
├── Routes/api.php
└── TicketsServiceProvider.php
```

Namespaces: `App\Modules\Tickets\...`. Each provider registers routes (prefix `/v1`, middleware group `tenant-api`), policies, event listeners, console commands and migration paths.

## Conventions

| Element | Rule |
|---|---|
| Controllers | One per resource, RESTful method names, no business logic; return resources or 204 |
| FormRequests | Validation rules + `authorize()` via `$this->user()->can('tickets.create')`; tenant-scoped uniqueness rules (`Rule::unique(...)->where('tenant_id', tenant('id'))`) |
| Resources | One `JsonResource` per exposed shape; no conditional shapes that break Scramble inference; timestamps ISO-8601 UTC |
| Policies | Record-level rules only (e.g. agent may update only assigned tickets when `tickets.update.own`); permission checks stay in FormRequests/middleware |
| Actions | `final class CreateTicket { public function __invoke(CreateTicketData $data, User $actor): Ticket }`; wraps `DB::transaction`, writes history/audit, dispatches events after commit (`Event::dispatch` inside `DB::afterCommit`) |
| Strategies | Algorithm contracts in `Contracts/`, implementations in `Strategies/<Name>/`, marked `#[AcademicBaseline]` when they are the defence baselines; bound in the module provider from config; callers type-hint the contract only |
| Domain services | Pure classes in `Domain/`, constructor-injected config DTOs, no Eloquent inside the algorithm core (adapters load inputs) |
| Queries | Only for list/report endpoints with many filters; return paginators; deterministic `ORDER BY` with `id` tie-breaker |
| Jobs | `use TenantAwareJob`; explicit `$tries`, `$backoff`, `$timeout`; idempotency keys where retried |
| Events / listeners | Events are immutable data (IDs + minimal payload); listeners are queued unless trivial; no listener calls another module's Action synchronously in a request path except Automation on ticket creation (bounded work) |
| Models | `HasUuids`, `BelongsToTenant` (primary) or `BelongsToPrimaryModel` (secondary); casts for enums/JSONB; no query logic beyond scopes |
| Enums | Backed string enums with `label()` and transition tables where relevant |
| DTOs | `readonly` classes constructed from FormRequests (`fromRequest`) |
| Exceptions | Domain exceptions extend `DomainException` with `code()` and `status()`; rendered as problem details ([error-handling.md](error-handling.md)) |
| Scheduled commands | Declared in module providers via `Schedule`, always `onOneServer()->withoutOverlapping()` |
| Repositories | Not used |
| Interfaces | Only `Clock`, `BusinessCalendar`, and Laravel's own driver contracts |

## Key sequences

Ticket creation ([user-flows F3](../02-product/user-flows.md)): `StoreTicketRequest` → `CreateTicket` action: allocate number (counter row lock) → persist ticket → `PriorityStrategy::score` → `SlaStrategy::start` → `DuplicateStrategy::find` (at most 50 candidates) → `AssignmentStrategy::choose` (if enabled) → history rows → commit → `TicketCreated` event → listeners (notifications, webhooks, broadcast) on queues.

SLA check: `sla:evaluate` every minute → `SlaStrategy::check(now)` for due timers → once-only transitions → events.

## Static analysis and style

Pint (`laravel` preset), Larastan level 5 in milestone 1, 6 from milestone 2; `declare(strict_types=1)`; architecture test (Pest `arch()` presets) enforcing that `Domain/` classes do not use `Illuminate\Database` and that modules only import allowed modules.
