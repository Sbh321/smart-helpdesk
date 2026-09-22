<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Models\Tenant;

/*
 * GET /v1/audit-logs (docs/04-domain/audit.md §Viewer, docs/07-api/pagination-filtering.md):
 * filters, cursor paging, names, permission and isolation.
 */

/** @param array<string, mixed> $attributes */
function auditRow(Tenant $tenant, string $action, string $at, array $attributes = []): AuditLog
{
    return $tenant->run(fn (): AuditLog => AuditLog::query()->create([
        'tenant_id' => $tenant->id,
        'actor_type' => ActorType::System,
        'action' => $action,
        'changes' => ['note' => $action],
        'created_at' => $at,
        ...$attributes,
    ]));
}

beforeEach(function (): void {
    $this->acme = createTenant('acme', ['timezone' => 'Asia/Kathmandu']);
    $this->globex = createTenant('globex');
    $this->admin = actingAsRole($this->acme, 'admin');
    $this->admin->forceFill(['name' => 'Priya Admin'])->save();
});

it('lists the workspace entries newest first with readable actor and subject names', function (): void {
    $role = Role::query()->create(['name' => 'triage', 'guard_name' => 'web', 'tenant_id' => $this->acme->id]);
    auditRow($this->acme, 'role.created', '2026-09-20 08:00:00', [
        'actor_type' => ActorType::User, 'actor_id' => $this->admin->id,
        'subject_type' => 'role', 'subject_id' => $role->id,
        'changes' => ['permissions' => ['tickets.view']],
        'ip_address' => '203.0.113.9', 'request_id' => 'req-1',
    ]);
    auditRow($this->acme, 'tenant.created', '2026-09-20 09:00:00', ['subject_type' => 'tenant', 'subject_id' => $this->acme->id]);

    $this->getJson('/v1/audit-logs')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'tenant.created')
        ->assertJsonPath('data.0.actor_type', 'system')
        ->assertJsonPath('data.0.actor_name', null)
        ->assertJsonPath('data.0.subject_name', null)
        ->assertJsonPath('data.1.action', 'role.created')
        ->assertJsonPath('data.1.actor_type', 'user')
        ->assertJsonPath('data.1.actor_id', $this->admin->id)
        ->assertJsonPath('data.1.actor_name', 'Priya Admin')
        ->assertJsonPath('data.1.subject_type', 'role')
        ->assertJsonPath('data.1.subject_name', 'triage')
        ->assertJsonPath('data.1.changes', ['permissions' => ['tickets.view']])
        ->assertJsonPath('data.1.ip_address', '203.0.113.9')
        ->assertJsonPath('data.1.request_id', 'req-1')
        ->assertJsonPath('data.1.created_at', '2026-09-20T08:00:00.000000Z')
        ->assertJsonStructure(['data', 'links', 'meta' => ['per_page', 'next_cursor', 'prev_cursor']]);
});

it('pages with a cursor without skipping or repeating entries written in the same second', function (): void {
    foreach (range(1, 5) as $n) {
        auditRow($this->acme, "user.invited{$n}", '2026-09-20 08:00:00');
    }
    auditRow($this->acme, 'user.newest', '2026-09-20 09:00:00');

    $first = $this->getJson('/v1/audit-logs?per_page=4')->assertOk()->assertJsonCount(4, 'data');
    $second = $this->getJson('/v1/audit-logs?per_page=4&cursor='.$first->json('meta.next_cursor'))->assertOk()->assertJsonCount(2, 'data');

    $actions = [...$first->json('data.*.action'), ...$second->json('data.*.action')];
    expect($actions[0])->toBe('user.newest')
        ->and(array_unique($actions))->toHaveCount(6)
        ->and($second->json('meta.next_cursor'))->toBeNull();
});

it('filters by action, action group, actor, subject and date range in the workspace zone', function (): void {
    $other = createTenantUser($this->acme);
    $subject = '0199a000-0000-7000-8000-000000000001';
    auditRow($this->acme, 'user.invited', '2026-09-19 12:00:00', ['actor_type' => ActorType::User, 'actor_id' => $this->admin->id, 'subject_type' => 'user', 'subject_id' => $subject]);
    auditRow($this->acme, 'user.disabled', '2026-09-19 20:00:00', ['actor_type' => ActorType::User, 'actor_id' => $other->id, 'subject_type' => 'user', 'subject_id' => $subject]);
    auditRow($this->acme, 'webhook.disabled', '2026-09-20 03:00:00', ['subject_type' => 'webhook_subscription', 'subject_id' => '0199a000-0000-7000-8000-000000000002']);
    auditRow($this->acme, 'api_client.created', '2026-09-20 04:00:00', ['actor_type' => ActorType::ApiClient, 'actor_id' => '0199a000-0000-7000-8000-000000000003']);

    $actions = fn (string $query): array => $this->getJson('/v1/audit-logs?'.$query)->assertOk()->json('data.*.action');

    expect($actions('filter[action]=user.invited'))->toBe(['user.invited'])
        ->and($actions('filter[action]=user.*'))->toBe(['user.disabled', 'user.invited'])
        ->and($actions('filter[action]=user.invited,webhook.disabled'))->toBe(['webhook.disabled', 'user.invited'])
        ->and($actions('filter[action]=api_client.*'))->toBe(['api_client.created'])
        ->and($actions('filter[actor_type]=system'))->toBe(['webhook.disabled'])
        ->and($actions('filter[actor_type]=api_client'))->toBe(['api_client.created'])
        ->and($actions('filter[actor_id]='.$other->id))->toBe(['user.disabled'])
        ->and($actions('filter[subject_type]=user&filter[subject_id]='.$subject))->toBe(['user.disabled', 'user.invited'])
        ->and($actions('filter[subject_type]=webhook_subscription'))->toBe(['webhook.disabled'])
        // Kathmandu is UTC+05:45: 19 Sep there runs 18 Sep 18:15 to 19 Sep 18:15 UTC.
        ->and($actions('filter[created_between]=2026-09-19,2026-09-19'))->toBe(['user.invited'])
        ->and($actions('filter[created_between]=2026-09-20,2026-09-20'))->toBe(['api_client.created', 'webhook.disabled', 'user.disabled']);
});

it('rejects unknown filters, bad values, sorting and page numbers', function (string $query, string $field): void {
    $this->getJson('/v1/audit-logs?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'unknown filter' => ['filter[ip]=1.2.3.4', 'filter.ip'],
    'unknown actor type' => ['filter[actor_type]=robot', 'filter.actor_type'],
    'actor id not a uuid' => ['filter[actor_id]=42', 'filter.actor_id'],
    'action with sql' => ['filter[action]=user%27--', 'filter.action'],
    'one date' => ['filter[created_between]=2026-09-01', 'filter.created_between'],
    'dates reversed' => ['filter[created_between]=2026-09-02,2026-09-01', 'filter.created_between'],
    'sort' => ['sort=action', 'sort'],
    'page' => ['page=2', 'page'],
    'page size' => ['per_page=500', 'per_page'],
]);

it('never shows another workspace\'s entries or platform-level entries', function (): void {
    auditRow($this->acme, 'settings.updated', '2026-09-20 08:00:00');
    $foreign = auditRow($this->globex, 'role.created', '2026-09-20 08:00:00', ['subject_type' => 'role', 'subject_id' => '0199a000-0000-7000-8000-000000000009']);
    tenancy()->end();
    AuditLog::query()->create(['tenant_id' => null, 'actor_type' => ActorType::PlatformUser, 'action' => 'tenant.suspended', 'subject_type' => 'tenant', 'subject_id' => $this->acme->id, 'changes' => ['reason' => 'x'], 'created_at' => '2026-09-20 09:00:00']);
    tenancy()->initialize($this->acme);

    $this->getJson('/v1/audit-logs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'settings.updated');
    $this->getJson('/v1/audit-logs?filter[action]=tenant.*')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/v1/audit-logs?filter[subject_id]='.$foreign->subject_id)->assertOk()->assertJsonCount(0, 'data');
});

it('does not name a user of another workspace', function (): void {
    $stranger = createTenantUser($this->globex, ['name' => 'Globex Person']);
    auditRow($this->acme, 'user.invited', '2026-09-20 08:00:00', ['actor_type' => ActorType::User, 'actor_id' => $stranger->id, 'subject_type' => 'user', 'subject_id' => $stranger->id]);

    $this->getJson('/v1/audit-logs')->assertOk()
        ->assertJsonPath('data.0.actor_name', null)
        ->assertJsonPath('data.0.subject_name', null);
});

it('requires audit.view', function (string $role, int $status): void {
    actingAsRole($this->acme, $role, createTenantUser($this->acme));

    $this->getJson('/v1/audit-logs')->assertStatus($status);
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'manager' => ['manager', 403],
    'agent' => ['agent', 403],
    'developer' => ['developer', 403],
]);

it('is not open to API clients, whatever their scopes', function (): void {
    require_once __DIR__.'/../Integrations/IntegrationTestHelpers.php';
    auth()->forgetGuards();
    $token = issueToken(createApiClient($this->acme, ['webhooks:manage']), 'webhooks:manage');

    $this->withToken($token)->getJson('/v1/audit-logs')->assertForbidden();
});

it('shows the writes of a request as the signed-in user', function (): void {
    /** @var User $admin */
    $admin = $this->admin;
    $this->postJson('/v1/roles', ['name' => 'viewer', 'permissions' => ['tickets.view']])->assertCreated();

    $this->getJson('/v1/audit-logs?filter[action]=role.created')->assertOk()
        ->assertJsonPath('data.0.actor_name', 'Priya Admin')
        ->assertJsonPath('data.0.actor_id', $admin->id)
        ->assertJsonPath('data.0.subject_name', 'viewer');
});
