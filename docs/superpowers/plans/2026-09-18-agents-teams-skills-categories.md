# Agents, Teams, Skills and Categories Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver M2-02's tenant-safe agent directory, organisation CRUD, shift scheduling, settings UI and immediate availability control.

**Architecture:** The Agents module takes ownership of `Skill` and adds Team, AgentProfile, pivot and shift persistence/API surfaces. Tickets retains Category and receives the missing composite foreign keys. `AgentDirectory` adapts Eloquent state into the existing pure assignment-domain inputs; React consumes only generated OpenAPI types.

**Tech Stack:** Laravel 13, PHP 8.4, Pest, PostgreSQL 18, React 19, TanStack Router/Query/Table/Form, Zod, Base UI, Vitest Browser, MSW.

**Spec:** `docs/superpowers/specs/2026-09-18-agents-teams-skills-categories-design.md`

## Global Constraints

- Preserve the uncommitted M2-06 work; do not commit or push.
- Every application-plane table has `tenant_id uuid NOT NULL`, tenant-leading unique keys, composite tenant foreign keys, tenant protection, registry coverage and a `ForTenant` factory.
- Code checks permissions, never role names; every `/v1` route has `can:<permission>`.
- Reportable tables receive change capture in their creating/altering migration.
- API shapes use JsonResources and regenerated Scramble/OpenAPI types.
- No dependency is added; frontend names are kebab-case and copy stays in `frontend/src/copy/en.ts`.
- Use TDD: observe each behavior test fail for the missing feature before production code.
- Replace normal commit steps with review checkpoints and final Conventional Commit suggestions.

---

### Task 1: Agent persistence and ownership

**Files:**
- Create: `backend/app/Modules/Agents/Database/Migrations/2026_09_18_120000_create_agent_directory_tables.php`
- Create: `backend/app/Modules/Agents/Enums/AgentAvailability.php`
- Create: `backend/app/Modules/Agents/Models/{Skill,Team,AgentProfile,TeamMember,AgentSkill,AgentShift}.php`
- Create: `backend/database/factories/{Skill,Team,AgentProfile,TeamMember,AgentSkill,AgentShift}Factory.php`
- Create: `backend/tests/Feature/Agents/AgentSchemaTest.php`
- Modify: `backend/app/Modules/Tickets/Models/Category.php`
- Delete: `backend/app/Modules/Tickets/Models/Skill.php`
- Modify: `backend/app/Modules/{Tenancy/Support/TenantTables,Reporting/Support/ReportableTables}.php`
- Modify: `backend/tests/Support/TenantModelInventory.php`

**Interfaces:** Produces all agent models, `AgentAvailability::{Available,Away,Offline}`, and composite FKs for category/ticket team and assigned agent.

- [ ] **Step 1: Write the failing schema and isolation tests**

```php
it('creates tenant-safe agent tables and ticket foreign keys', function (): void {
    expect(Schema::hasColumns('agent_profiles', [
        'id', 'tenant_id', 'user_id', 'capacity', 'availability',
        'active_ticket_count', 'last_assigned_at',
    ]))->toBeTrue();
    expect(compositeForeignKey('tickets', ['tenant_id', 'team_id']))
        ->toBe(['teams', ['tenant_id', 'id']]);
    expect(compositeForeignKey('tickets', ['tenant_id', 'assigned_agent_id']))
        ->toBe(['agent_profiles', ['tenant_id', 'id']]);
});
```

Add cases for cross-tenant team/agent/category links, capacity 1–100, skill level 1–5, availability values, and shift weekday/date/time checks.

- [ ] **Step 2: Run RED**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/AgentSchemaTest.php tests/Isolation/ModelReflectionTest.php`

Expected: missing agent tables/models and foreign keys.

- [ ] **Step 3: Implement the minimal migration, models, factories and registries**

Use tenant-leading keys:

```php
$table->unique(['tenant_id', 'id']);
$table->primary(['tenant_id', 'agent_profile_id', 'skill_id']);
$table->unsignedSmallInteger('level');
$table->check('level BETWEEN 1 AND 5');
$table->check("availability IN ('available', 'away', 'offline')");
$table->check('(weekday IS NULL) <> (date IS NULL)');
$table->check('ends_at > starts_at');
```

Register every table/model, use `BelongsToTenant` and `ForTenant`, explicitly protect each table, add the six reportable tables from the spec, attach the deferred `skills` change-capture trigger, move Skill to Agents, and update Category imports.

- [ ] **Step 4: Run GREEN**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/AgentSchemaTest.php tests/Isolation tests/Feature/Reporting/ChangeCaptureSchemaTest.php`

- [ ] **Step 5: Review checkpoint**

Run `git diff --check`; inspect migration rollback order, constraints and registries. Do not commit.

---

### Task 2: Relationships, shifts and AgentDirectory

**Files:**
- Create: `backend/app/Modules/Agents/Queries/{AgentPool,AgentDirectory,AgentWorkload}.php`
- Create: `backend/tests/Feature/Agents/AgentDirectoryTest.php`
- Modify: agent models, `backend/app/Modules/Tickets/Models/{Category,Ticket}.php`, and `backend/app/Models/User.php`

**Interfaces:**
- `AgentPool::__construct(TicketNeeds $needs, array $candidates)` with `list<AgentCandidate>`.
- `AgentDirectory::forTicket(Ticket $ticket): AgentPool`.
- `AgentWorkload::for(AgentProfile $agent): array{active_ticket_count:int,capacity:int,load:float,by_priority:array{P1:int,P2:int,P3:int,P4:int}}`.

- [ ] **Step 1: Write failing directory tests**

```php
$pool = app(AgentDirectory::class)->forTicket($ticket);
expect($pool->needs->requiredSkills)->toBe(['billing'])
    ->and($pool->needs->teamId)->toBe($defaultTeam->id)
    ->and($pool->candidates[0]->openTickets)->toBe(2)
    ->and($pool->candidates[0]->skills)->toBe(['billing']);
```

Cover inactive users, availability, exact active statuses, capacity, all skills, team/default team, shifts disabled, weekly shift, date override, day off, tenant time zone and stable ordering.

- [ ] **Step 2: Run RED**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/AgentDirectoryTest.php`

- [ ] **Step 3: Implement query adapters without duplicating strategy rules**

```php
return new AgentPool(
    new TicketNeeds($ticket->id, $skillSlugs, $teamId, $this->shiftsEnforced()),
    $profiles->map(fn (AgentProfile $profile): AgentCandidate => new AgentCandidate(
        id: $profile->id,
        openTickets: $counts[$profile->id] ?? 0,
        capacity: $profile->capacity,
        skills: $profile->skills->pluck('slug')->sort()->values()->all(),
        teamIds: $profile->teams->pluck('id')->sort()->values()->all(),
        active: $profile->user->is_active,
        available: $profile->availability === AgentAvailability::Available,
        onShift: $this->onShift($profile),
        lastAssignedAt: $profile->last_assigned_at,
    ))->all(),
);
```

Aggregate workload in one query and eager-load relations; read shift enforcement behind one method using the current config fallback.

- [ ] **Step 4: Run GREEN**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/AgentDirectoryTest.php tests/Contracts/BaselineStrategiesTest.php tests/Unit/Automation/LeastLoadedAgentTest.php`

- [ ] **Step 5: Review checkpoint**

Inspect query counts and verify the existing strategy remains the owner of eligibility/exclusions. Do not commit.

---

### Task 3: Skill, team and category APIs

**Files:**
- Create: Agents controllers/requests/resources/queries for Skills and Teams under `backend/app/Modules/Agents/Http` and `Queries`
- Create: `backend/app/Modules/Agents/Routes/api.php`
- Create: `backend/tests/Feature/Agents/SkillTeamApiTest.php`
- Modify: Tickets Category controller/resource/routes
- Create: `backend/app/Modules/Tickets/Http/Requests/{IndexCategoriesRequest,SaveCategoryRequest}.php`
- Create: `backend/tests/Feature/Tickets/CategoryManagementApiTest.php`

**Interfaces:** Paginated Skill/Team/Category CRUD; `PUT /teams/{team}/members`; category `default_team_id` and `required_skills`.

- [ ] **Step 1: Write failing CRUD, permission and isolation tests**

```php
actingAsRole($tenant, 'manager')
    ->putJson("/v1/teams/{$team->id}/members", ['agent_ids' => [$a->id, $b->id]])
    ->assertOk()->assertJsonCount(2, 'data.members');

actingAsRole($tenant, 'admin')->postJson('/v1/categories', [
    'name' => 'Billing', 'default_team_id' => $team->id,
    'skill_ids' => [$refunds->id], 'is_active' => true, 'sort_order' => 10,
])->assertCreated()->assertJsonCount(1, 'data.required_skills');
```

Cover pagination, duplicate names/slugs, foreign ids, atomic replacement, delete conflicts, route
permissions and a `team.members_changed` security-audit row containing old/new member ids.

- [ ] **Step 2: Run RED**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/SkillTeamApiTest.php tests/Feature/Tickets/CategoryManagementApiTest.php`

- [ ] **Step 3: Implement requests, resources, queries, controllers and routes**

GET Skills/Teams use `agents.view`; Skill writes use `agents.manage`; Team writes/members use
`teams.manage`; Category writes use `settings.manage`. Lock parents during relation replacement,
validate every id with a tenant-scoped query, and call `Audit::record('team.members_changed', ...)`
after a successful membership change.

```php
Route::get('/skills', [SkillController::class, 'index'])->middleware('can:agents.view');
Route::post('/skills', [SkillController::class, 'store'])->middleware('can:agents.manage');
Route::patch('/skills/{skill}', [SkillController::class, 'update'])->middleware('can:agents.manage');
Route::delete('/skills/{skill}', [SkillController::class, 'destroy'])->middleware('can:agents.manage');
Route::get('/teams', [TeamController::class, 'index'])->middleware('can:agents.view');
Route::put('/teams/{team}/members', [TeamController::class, 'replaceMembers'])->middleware('can:teams.manage');
```

- [ ] **Step 4: Run GREEN**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/SkillTeamApiTest.php tests/Feature/Tickets/CategoryManagementApiTest.php tests/Feature/Tickets tests/Permissions/RouteProtectionTest.php`

- [ ] **Step 5: Review checkpoint**

Confirm existing ticket category response compatibility and no client-supplied tenant id. Do not commit.

---

### Task 4: Agent profile, workload, session and shift APIs

**Files:**
- Create: `backend/app/Modules/Agents/Http/Controllers/{AgentController,AgentShiftController}.php`
- Create: Agents requests/resources for agent CRUD, skill replacement and shifts
- Create: `backend/app/Modules/Agents/Actions/ReplaceAgentShifts.php`
- Create: `backend/app/Modules/Agents/Queries/AgentListQuery.php`
- Modify: Agents routes and `backend/app/Modules/Identity/Http/Resources/MeResource.php`
- Create: `backend/tests/Feature/Agents/{AgentApiTest,AgentShiftApiTest}.php`

**Interfaces:** Agent CRUD, available-user picker, skills with levels, workload, shifts, and nullable `/me.data.agent_profile`.

- [ ] **Step 1: Write failing authorization and behavior tests**

```php
actingAsTenantUser($agent->user)
    ->patchJson("/v1/agents/{$agent->id}", ['availability' => 'away'])
    ->assertOk()->assertJsonPath('data.availability', 'away');

actingAsTenantUser($agent->user)
    ->patchJson("/v1/agents/{$agent->id}", ['capacity' => 50])
    ->assertForbidden();
```

Cover manager CRUD, available users, list filters, skill levels, workload counts, `/me`, shift
replacement, overlap validation, own shift read, manager edit, cross-tenant ids and audit rows for
manager changes to capacity, skills and shifts.

- [ ] **Step 2: Run RED**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents/AgentApiTest.php tests/Feature/Agents/AgentShiftApiTest.php`

- [ ] **Step 3: Implement profile and shift endpoints**

Agent PATCH route uses `can:agents.view`; its FormRequest permits `agents.manage`, or the owning user only when request keys are exactly `['availability']`. Skill rows are `{skill_id,level}`. Shift GET permits own profile or `shifts.manage`; PUT requires `shifts.manage`, locks the profile, rejects overlapping normalized buckets and replaces rows transactionally.

```php
public function authorize(): bool
{
    $agent = $this->route('agent');
    $actor = $this->user();

    return $actor?->can('agents.manage') === true
        || ($agent instanceof AgentProfile
            && $agent->user_id === $actor?->id
            && array_keys($this->all()) === ['availability']);
}
```

- [ ] **Step 4: Run GREEN**

Run: `docker compose exec -T app vendor/bin/pest tests/Feature/Agents tests/Feature/Identity tests/Permissions`

- [ ] **Step 5: Review checkpoint**

Confirm permission names, response shapes, locks and cross-tenant 404 behavior. Do not commit.

---

### Task 5: OpenAPI, typed frontend adapters and MSW

**Files:**
- Modify generated: `backend/openapi.json`, `frontend/src/lib/api/schema.d.ts`
- Create: `frontend/src/features/agents/api/agent-queries.ts`
- Create: `frontend/src/features/agents/schemas.ts` and `schemas.test.ts`
- Modify: `frontend/src/lib/api/query-keys.ts`
- Create: `frontend/src/test/msw/agents.ts`
- Modify: `frontend/src/test/msw/{browser,data}.ts`

**Interfaces:** Typed queries/mutations and resettable MSW Skill, Team, Category, Agent, Workload and Shift fixtures.

- [ ] **Step 1: Regenerate the contract**

Run `docker compose exec -T app php artisan scramble:export --path=openapi.json`, then `mise exec -- pnpm api:types` from `frontend/`; review required fields and enums.

- [ ] **Step 2: Write schema tests and observe RED**

```ts
expect(agentSchema.safeParse({ capacity: 0, availability: 'available', skills: [] }).success).toBe(false)
expect(skillAssignmentSchema.safeParse({ skill_id: crypto.randomUUID(), level: 6 }).success).toBe(false)
```

Run: `mise exec -- pnpm test -- schemas.test.ts`.

- [ ] **Step 3: Implement adapters, schemas, query keys and MSW state**

All response aliases come from `components['schemas']`; MSW implements pagination, filters, CRUD, relation replacement, workload, shifts and availability against shared `db`.

```ts
export type Agent = components['schemas']['AgentResource']
export type Team = components['schemas']['TeamResource']
export const agentQueries = {
  list: (tenantId: string, query: ApiListQuery) => queryOptions({
    queryKey: queryKeys.agents.list(tenantId, query),
    queryFn: () => unwrapBody(api().GET('/agents', { params: { query } })),
  }),
}
```

- [ ] **Step 4: Run GREEN**

Run: `mise exec -- pnpm test && mise exec -- pnpm typecheck`.

- [ ] **Step 5: Review checkpoint**

Confirm no handwritten response types duplicate the generated schema. Do not commit.

---

### Task 6: Settings directory pages

**Files:**
- Create: `frontend/src/features/agents/components/{settings-layout,skill-settings,team-settings,category-settings,agent-settings}.tsx`
- Create: `frontend/src/features/agents/components/settings-directory.browser.test.tsx`
- Create: `frontend/src/features/agents/index.ts`
- Modify: `frontend/src/routes/$workspace/_app/settings.tsx`
- Create: `frontend/src/routes/$workspace/_app/settings/{index,skills,teams,categories,agents}.tsx`
- Modify: `frontend/src/copy/en.ts`, generated `frontend/src/routeTree.gen.ts`

**Interfaces:** Settings navigation and URL-bound server tables/forms consuming Task 5 adapters.

- [ ] **Step 1: Write browser tests and observe RED**

```tsx
const { screen } = await renderApp('/acme/settings/skills')
await screen.getByRole('button', { name: copy.agents.skills.create }).click()
await screen.getByRole('textbox', { name: copy.agents.skills.name }).fill('PostgreSQL')
await screen.getByRole('button', { name: copy.agents.skills.save }).click()
await expect.element(screen.getByRole('cell', { name: 'PostgreSQL' })).toBeVisible()
```

Cover forbidden state, URL pagination/filtering, Team members, Category required skills/default team, Agent capacity/skills/levels/teams, loading/empty/error and mutation failures.

- [ ] **Step 2: Run RED**

Run: `mise exec -- pnpm test:browser -- settings-directory.browser.test.tsx`.

- [ ] **Step 3: Implement the layout, tables and forms**

Reuse DataTable, Dialog, EntityCombobox, SelectField and FormErrorBanner; keep all copy centralized and do not import features from components.

```tsx
export function SettingsLayout() {
  const { workspace } = useParams({ strict: false })
  return (
    <div className="grid gap-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
      <nav aria-label={copy.settings.sections}>{/* typed section links */}</nav>
      <Outlet />
    </div>
  )
}
```

- [ ] **Step 4: Run GREEN**

Run: `mise exec -- pnpm test:browser -- settings-directory.browser.test.tsx`.

- [ ] **Step 5: Review checkpoint**

Check keyboard operation, responsive layout, crumbs and all UI states. Do not commit.

---

### Task 7: Shift editor and Topbar availability

**Files:**
- Create: `frontend/src/features/agents/components/{shift-editor,availability-control}.tsx`
- Create: `frontend/src/features/agents/components/agent-availability.browser.test.tsx`
- Create: `frontend/src/routes/$workspace/_app/settings/shifts.tsx`
- Modify: `frontend/src/components/layout/topbar.tsx`, session cache owner, `frontend/src/copy/en.ts`

**Interfaces:** Weekly/date-exception editor and optimistic availability control sourced from `/me.agent_profile`.

- [ ] **Step 1: Write interaction, rollback and axe tests; observe RED**

```tsx
await screen.getByRole('button', { name: copy.agents.availability.label }).click()
await screen.getByRole('menuitem', { name: copy.agents.availability.away }).click()
await expect.element(screen.getByRole('button', { name: /Away/ })).toBeVisible()
```

Add a problem-details response case asserting rollback/error feedback, shift add/remove/date exception submission, non-agent absence and axe.

- [ ] **Step 2: Run RED**

Run: `mise exec -- pnpm test:browser -- agent-availability.browser.test.tsx`.

- [ ] **Step 3: Implement the controls**

Snapshot and optimistically update both Agent detail and `queryKeys.session.me()`, restore on error, then invalidate both domains. Render availability only for a non-null profile.

```tsx
const change = useMutation({
  mutationFn: (availability: AgentAvailability) => updateAgent(profile.id, { availability }),
  onMutate: async (availability) => {
    await client.cancelQueries({ queryKey: queryKeys.session.me() })
    const previous = client.getQueryData<Session>(queryKeys.session.me())
    client.setQueryData(queryKeys.session.me(), optimisticAvailability(previous, availability))
    return { previous }
  },
  onError: (_error, _value, context) => client.setQueryData(queryKeys.session.me(), context?.previous),
})
```

- [ ] **Step 4: Run GREEN**

Run: `mise exec -- pnpm test:browser -- agent-availability.browser.test.tsx app-shell.browser.test.tsx accessibility.browser.test.tsx`.

- [ ] **Step 5: Review checkpoint**

Verify ordinary agents change only availability and shift editor displays workspace time zone. Do not commit.

---

### Task 8: Documentation, verification and closure

**Files:**
- Modify: `docs/04-domain/agents-and-teams.md`, `docs/08-database/{entities,indexing}.md`, `docs/07-api/conventions.md`, `docs/03-architecture/frontend.md`
- Modify if applicable: `docs/06-design-system/components.md`
- Modify: `roadmap/02-week-1-foundation.md`, `roadmap/03-week-2-product.md`

**Interfaces:** Synchronized documentation and honest M2-02 status/evidence.

- [ ] **Step 1: Update docs and remove only closed shortcuts**

Record actual API shapes, skill levels, shift precedence, directory adapter, settings routes and availability behavior. Remove the M1-17 missing-FK shortcuts.

- [ ] **Step 2: Run backend verification**

Run full Pest, Pint and `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` inside the app container.

- [ ] **Step 3: Regenerate and run frontend verification**

Regenerate OpenAPI/types, then run `pnpm lint`, `typecheck`, `test`, `test:browser`, `tokens:check` and `build` through `mise exec --`.

- [ ] **Step 4: Deploy and smoke**

Run `docker compose build proxy`, `docker compose up -d --wait proxy`, then `infra/scripts/smoke.sh`.

- [ ] **Step 5: Close only when complete**

If every acceptance/DoD item passes, mark M2-02 `[x]` and add a `- **Done (2026-09-18):**` bullet with deviations first and evidence second. Otherwise leave `[~]` with precise progress.

- [ ] **Step 6: Final checkpoint**

Run `git diff --check`, inspect `git status --short`, ensure no unrelated work was overwritten, and provide Conventional Commit suggestions without committing.
