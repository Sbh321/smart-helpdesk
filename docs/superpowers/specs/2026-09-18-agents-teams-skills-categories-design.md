# Agents, Teams, Skills and Categories Design

**Roadmap task:** M2-02  
**Status:** Approved for planning on 2026-09-18  
**Authoritative product decisions:** `docs/04-domain/agents-and-teams.md`,
`docs/08-database/entities.md`, `docs/07-api/conventions.md`, ADR-0020

## Goal

Deliver the tenant-scoped organisation directory used by assignment: administrators manage skills,
teams, categories, agent profiles and shifts; agents can change their own availability; and
`AgentDirectory` produces current eligible candidates and workload data for M2-05.

## Scope

M2-02 includes the Must-have agent, team, skill and category capabilities and the Should-have shift
schedule already accepted by ADR-0020. It does not assign tickets, run the assignment strategy,
maintain assignment counters from ticket lifecycle events, or expose assignment explanations; M2-05
owns those behaviours. Until M2-05 lands, workload is calculated from the current ticket rows so it
cannot drift from reality.

M2-01 owns the persisted tenant settings service. M2-02 reads `shifts.enforce` through a small
configuration seam that uses the existing `config('helpdesk.shifts.enforce')` default until that
service exists, without introducing a second settings implementation.

## Module ownership

The existing `App\Modules\Agents` module owns:

- `Skill`, including the table originally introduced by Tickets in M1-17;
- `Team`, `AgentProfile` and `AgentShift`;
- team-membership and agent-skill persistence;
- CRUD requests, resources, controllers and routes for agents, teams and skills;
- shift schedule replacement and availability changes;
- `AgentDirectory`, the persistence-aware candidate loader used by M2-05.

`Category` remains owned by Tickets because it classifies tickets. Tickets exposes category CRUD and
syncs required skills and the optional default team. Its relation imports the Agents `Skill` and
`Team` models. Moving the existing `Skill` PHP class changes ownership without recreating or copying
the existing `skills` table.

## Persistence design

A new Agents migration creates `teams`, `agent_profiles`, `team_members`, `agent_skills` and
`agent_shifts`, then alters the existing tables:

- `categories.default_team_id` receives the composite foreign key
  `(tenant_id, default_team_id) -> teams(tenant_id, id)` with `SET NULL` semantics;
- `tickets.team_id` receives the equivalent nullable Team foreign key;
- `tickets.assigned_agent_id` receives the equivalent nullable AgentProfile foreign key;
- the obsolete M1-17 shortcut comments are removed when these constraints exist.

Every new table has `tenant_id uuid NOT NULL`; relation foreign keys include `tenant_id`, and every
unique or primary index begins with `tenant_id`. `agent_skills.level` is an integer from 1 to 5;
the MVP directory records it for administration and future strategies, while the baseline requires
skill presence only. Every new model, including explicit pivot and shift models, uses `BelongsToTenant`,
is classified in `TenantModelInventory::PRIMARY`, and has a factory using `ForTenant`. All tables are
added to `TenantTables::PRIMARY`, and `protectTenantId()` is called for an explicit table list in this
migration. `skills`, `teams`, `agent_profiles`, `team_members`, `agent_skills` and `agent_shifts` are
reportable organisational state and receive change-capture triggers from the same migration after
registration in `ReportableTables`; the migration also attaches the previously deferred trigger to
the existing `skills` table.

Database checks enforce capacity 1–100, non-negative active counts, the availability vocabulary,
weekday 0–6, exactly one of weekday/date on a shift, and a valid positive time window. The migration
is reversible: it drops the three added foreign keys before dropping the new tables.

## Domain and query behaviour

`AgentDirectory::forTicket(Ticket $ticket)` uses the injected `Clock` and returns an `AgentPool` with
the ticket's `TicketNeeds` and a stable list of every tenant `AgentCandidate`. Candidates contain
identity, capacity, current active-ticket count, skills, teams, user-active state, availability,
on-shift state and last assignment time. M2-05 passes this pool to `AssignmentStrategy`, which remains
the single owner of eligibility order and exclusion reasons. Eligibility requires all of:

1. the user is active;
2. availability is `available`;
3. active workload is below capacity;
4. every category-required skill is present;
5. the agent belongs to the ticket team, or to the category default team when it supplies the team;
6. when shift enforcement is enabled, the current instant falls inside the weekly template after a
   matching date exception has overridden it.

The query calculates workload from tickets in `assigned`, `in_progress` or `pending`. The stored
`active_ticket_count` remains present for M2-05's locked counter maintenance and nightly
reconciliation, but M2-02 responses do not trust it as the source of truth.

Shift times are interpreted in the tenant time zone. A date exception replaces that weekday's
template; an `is_off` exception makes the whole date unavailable. Overlapping rows for the same
template weekday or exception date are rejected during schedule replacement.

## HTTP API

The documented `/v1` inventory is implemented with FormRequests, JsonResources and permission
middleware:

- skills: paginated `GET`, plus `POST`, `PATCH` and guarded `DELETE`;
- teams: paginated `GET`, plus `POST`, `GET`, `PATCH`, guarded `DELETE`, and atomic member replacement;
- categories: extend the existing list with `POST`, `PATCH` and guarded `DELETE`, syncing required
  skills and validating the default team inside the tenant;
- agents: paginated `GET` with team, skill and availability filters; `POST`, `GET/PATCH/DELETE`
  profile; atomic skill replacement with levels; workload endpoint returning active count, capacity
  and counts by effective priority; profile deletion is refused while active tickets reference it;
- agent user picker: `GET /agents/available-users`, protected by `agents.manage`, returns active
  tenant users without a profile for the create form; M2-12 remains responsible for the general
  users management API and UI;
- shifts: `GET/PUT /agents/{agent}/shifts`, where managers can edit and an agent can read their own;
- availability: profile PATCH permits an agent to change only their own availability, while
  `agents.manage` can change capacity and the complete profile.

Every route uses a permission from the existing catalogue. The agent PATCH route is protected by
`agents.view`, then request authorization permits either an `agents.manage` holder or the profile's
own user changing only `availability`; capacity, teams and skills remain manager-only. Cross-tenant
identifiers resolve as 404.
Deletes return conflict or validation errors when referenced instead of silently losing routing
configuration. `/v1/me` adds a nullable agent-profile summary so the Topbar can render the signed-in
agent's current availability without scanning the directory.

## Frontend

The Settings placeholder becomes a settings layout with nested Skills, Teams, Categories, Agents and
Shifts pages. Each list uses the existing URL-bound server DataTable conventions. Forms use the
existing Base UI primitives and Combobox for members, skills and default team. Mutations expose field
errors, a form-level problem banner and success feedback, then invalidate their domain query keys.

The Agents page shows user, availability, capacity, active workload, skills and teams. The shift
editor is a weekly grid plus date exceptions and uses the workspace time zone visibly. Users with an
agent profile receive a permission-aware availability control in the Topbar; it applies the change
optimistically and restores the previous state when the request fails.

All copy remains in `frontend/src/copy/en.ts`. Components do not import features, list state remains
in URL parameters, and no dependency is added.

## Error and concurrency behaviour

All writes validate referenced records through tenant-scoped queries. Member, skill and shift
replacement runs in a transaction and locks the parent record so concurrent replacements cannot
interleave. Team membership changes and manager changes to agent capacity, skills or shifts write
named security-audit entries; database change capture retains complete organisational history.
Referenced-resource conflicts use the stable `conflict` problem-details code; ordinary field failures
remain validation problem details. Availability changes update the API response and Topbar
immediately; assignment eligibility reads fresh database state rather than a cache.

## Testing and evidence

Backend TDD covers schema constraints, composite foreign keys, tenant isolation, CRUD validation,
permission protection, atomic relation replacement, self-versus-manager availability, workload and
every eligibility/exclusion rule including shifts and time-zone/date exceptions. The existing route,
model-inventory, schema, change-capture and architecture suites must remain green.

Frontend browser tests cover list URL state, create/edit forms, Combobox relations, availability
optimism/rollback, the weekly shift editor, forbidden states and axe scans. MSW uses the shared
in-memory data set and mirrors the generated OpenAPI contract. Final evidence includes regenerated
OpenAPI/types, Pint, PHPStan at the repository's current configured level, Biome, TypeScript, unit and
browser suites, production build, proxy rebuild and smoke checks.

## Delivery state

M2-02 becomes complete only when the API and settings UI are both usable, the ticket/category foreign
keys are installed, the directory tests demonstrate immediate availability changes, linked docs are
updated, and all applicable Definition of Done checks pass. No assignment behaviour is claimed before
M2-05.
