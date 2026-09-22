<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Integrations\Actions\SetWebhookActive;
use App\Modules\Integrations\Models\WebhookSubscription;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

require_once __DIR__.'/../Integrations/IntegrationTestHelpers.php';
require_once __DIR__.'/../Integrations/WebhookTestHelpers.php';

/*
 * Every audited action of docs/04-domain/audit.md §Audited actions, performed through its API (or,
 * for the system actor, the action the scheduler runs), leaves one audit row with the expected actor
 * type, subject and non-empty changes (roadmap M3-03 acceptance).
 */

/** @return array{ActorType, string, bool} actor type, subject type, and whether the row is platform-level */
function auditScenario(TestCase $test, string $scenario): array
{
    /** @var Tenant $acme */
    $acme = $test->acme;
    $owner = fn (): User => actingAsRole($acme, 'owner');
    $member = function (string $role) use ($acme): User {
        $user = createTenantUser($acme);
        $acme->run(fn () => $user->syncRoles([$role]));

        return $user;
    };
    $platform = function () use ($test): void {
        $test->actingAs(PlatformUser::query()->create(['name' => 'Platform admin', 'email' => 'admin@platform.test', 'password' => 'platform-password']), 'platform');
    };
    $onPlatform = fn (string $path): string => 'https://'.config('helpdesk.hosts.admin').'/platform-api/'.$path;
    $policy = fn (): string => $test->postJson('/v1/sla-policies', [
        'name' => 'Premium', 'applies_to_tier' => 'premium', 'warning_fraction' => 0.5,
        'targets' => [
            ['priority_level' => 'P1', 'first_response_minutes' => 10, 'resolution_minutes' => 60],
            ['priority_level' => 'P2', 'first_response_minutes' => 20, 'resolution_minutes' => 120],
            ['priority_level' => 'P3', 'first_response_minutes' => 30, 'resolution_minutes' => 180],
            ['priority_level' => 'P4', 'first_response_minutes' => 40, 'resolution_minutes' => 240],
        ],
    ])->assertCreated()->json('data.id');
    $webhook = fn (): WebhookSubscription => createWebhook($acme);

    switch ($scenario) {
        case 'user.invited':
            $owner();
            $test->postJson('/v1/users/invitations', ['name' => 'Chen', 'email' => 'chen@acme.test', 'roles' => ['agent']])->assertCreated();

            return [ActorType::User, 'user', false];
        case 'user.invitation_resent':
            $owner();
            $id = $test->postJson('/v1/users/invitations', ['name' => 'Chen', 'email' => 'chen@acme.test', 'roles' => ['agent']])->json('data.id');
            $test->postJson("/v1/users/{$id}/invitation")->assertSuccessful();

            return [ActorType::User, 'user', false];
        case 'user.role_changed':
            $owner();
            $test->patchJson('/v1/users/'.$member('agent')->id, ['roles' => ['manager']])->assertOk();

            return [ActorType::User, 'user', false];
        case 'user.disabled':
            $owner();
            $test->postJson('/v1/users/'.$member('agent')->id.'/disable')->assertOk();

            return [ActorType::User, 'user', false];
        case 'user.enabled':
            $owner();
            $id = $member('agent')->id;
            $test->postJson("/v1/users/{$id}/disable")->assertOk();
            $test->postJson("/v1/users/{$id}/enable")->assertOk();

            return [ActorType::User, 'user', false];
        case 'settings.updated':
            $owner();
            $test->patchJson('/v1/settings/tickets', ['reopen_window_days' => 3])->assertOk();

            return [ActorType::User, 'tenant_setting', false];
        case 'settings.updated (priority weights)':
            $owner();
            $test->patchJson('/v1/settings/automation.priority', ['baseline' => ['weights' => ['impact' => 0.5, 'urgency' => 0.25]]])->assertOk();

            return [ActorType::User, 'tenant_setting', false];
        case 'role.created':
            $owner();
            $test->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view']])->assertCreated();

            return [ActorType::User, 'role', false];
        case 'role.permissions_changed':
            $owner();
            $id = $test->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view']])->json('data.id');
            $test->patchJson("/v1/roles/{$id}", ['permissions' => ['tickets.view', 'tickets.update']])->assertOk();

            return [ActorType::User, 'role', false];
        case 'role.deleted':
            $owner();
            $id = $test->postJson('/v1/roles', ['name' => 'triage', 'permissions' => ['tickets.view']])->json('data.id');
            $test->deleteJson("/v1/roles/{$id}")->assertNoContent();

            return [ActorType::User, 'role', false];
        case 'api_client.created':
            $owner();
            $test->postJson('/v1/api-clients', ['name' => 'Monitoring', 'scopes' => ['tickets:read']])->assertCreated();

            return [ActorType::User, 'api_client', false];
        case 'api_client.revoked':
            $owner();
            $test->postJson('/v1/api-clients/'.createApiClient($acme)->id.'/revoke')->assertOk();

            return [ActorType::User, 'api_client', false];
        case 'webhook.created':
            $owner();
            $test->postJson('/v1/webhooks', ['name' => 'CRM', 'url' => 'https://hooks.example.com/a', 'events' => ['ticket.created']])->assertCreated();

            return [ActorType::User, 'webhook_subscription', false];
        case 'webhook.created (by an API client)':
            $token = issueToken(createApiClient($acme, ['webhooks:manage']), 'webhooks:manage');
            $test->withToken($token)->postJson('/v1/webhooks', ['name' => 'CRM', 'url' => 'https://hooks.example.com/a', 'events' => ['ticket.created']])->assertCreated();
            tenancy()->initialize($acme);

            return [ActorType::ApiClient, 'webhook_subscription', false];
        case 'webhook.updated':
            $owner();
            $test->patchJson('/v1/webhooks/'.$webhook()->id, ['name' => 'Renamed'])->assertOk();

            return [ActorType::User, 'webhook_subscription', false];
        case 'webhook.disabled':
            $owner();
            $test->postJson('/v1/webhooks/'.$webhook()->id.'/disable')->assertOk();

            return [ActorType::User, 'webhook_subscription', false];
        case 'webhook.disabled (after failures)':
            $subscription = $webhook();
            $acme->run(fn () => app(SetWebhookActive::class)($subscription, false, WebhookSubscription::DISABLED_FAILURES));
            tenancy()->initialize($acme);

            return [ActorType::System, 'webhook_subscription', false];
        case 'sla_policy.updated':
            $owner();
            $test->patchJson('/v1/sla-policies/'.$policy(), ['name' => 'Premium plus', 'warning_fraction' => 0.6])->assertOk();

            return [ActorType::User, 'sla_policy', false];
        case 'ticket.priority_overridden':
            $owner();
            $ticket = Ticket::factory()->forTenant($acme)->create(['impact' => 1, 'urgency' => 1, 'priority_score' => 0, 'priority_level' => Priority::P4]);
            $test->postJson("/v1/tickets/{$ticket->id}/priority", ['level' => 'P1', 'reason' => 'VIP outage'])->assertOk();

            return [ActorType::User, 'ticket', false];
        case 'tenant.created':
            $platform();
            $test->postJson($onPlatform('tenants'), ['slug' => 'initech', 'name' => 'Initech', 'owner_email' => 'owner@initech.test'])->assertCreated();

            return [ActorType::PlatformUser, 'tenant', true];
        case 'tenant.suspended':
            $platform();
            $test->postJson($onPlatform("tenants/{$acme->id}/suspend"), ['reason' => 'Unpaid'])->assertOk();

            return [ActorType::PlatformUser, 'tenant', true];
        case 'tenant.reactivated':
            $platform();
            $test->postJson($onPlatform("tenants/{$acme->id}/suspend"))->assertOk();
            $test->postJson($onPlatform("tenants/{$acme->id}/reactivate"))->assertOk();

            return [ActorType::PlatformUser, 'tenant', true];
    }

    throw new LogicException("No scenario for {$scenario}.");
}

beforeEach(function (): void {
    Notification::fake();
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 09:00:00'));
    $this->acme = createTenant('acme', ['timezone' => 'UTC']);
    fakeWebhookDns();
});

it('writes an audit row with the actor type, subject and changes', function (string $scenario): void {
    [$actorType, $subjectType, $platformLevel] = auditScenario($this, $scenario);
    $action = explode(' ', $scenario)[0];

    if ($platformLevel) {
        tenancy()->end();
    }
    $entry = AuditLog::query()->where('action', $action)->orderByDesc('created_at')->orderByDesc('id')->first();

    expect($entry)->not->toBeNull("{$action} wrote no audit row")
        ->and($entry->actor_type)->toBe($actorType)
        ->and($entry->subject_type)->toBe($subjectType)
        ->and($entry->subject_id)->not->toBeNull()
        ->and($entry->changes)->not->toBeEmpty()
        ->and($entry->tenant_id)->toBe($platformLevel ? null : $this->acme->id);
    if ($actorType !== ActorType::System) {
        expect($entry->actor_id)->not->toBeNull();
    }
})->with([
    'user.invited', 'user.invitation_resent', 'user.role_changed', 'user.disabled', 'user.enabled',
    'settings.updated', 'settings.updated (priority weights)',
    'role.created', 'role.deleted', 'role.permissions_changed',
    'api_client.created', 'api_client.revoked',
    'webhook.created', 'webhook.created (by an API client)', 'webhook.updated', 'webhook.disabled', 'webhook.disabled (after failures)',
    'sla_policy.updated',
    'tenant.created', 'tenant.suspended', 'tenant.reactivated',
    'ticket.priority_overridden',
]);

it('records the old and new values of an SLA policy change', function (): void {
    auditScenario($this, 'sla_policy.updated');

    $entry = AuditLog::query()->where('action', 'sla_policy.updated')->sole();
    expect($entry->changes)->toEqual([
        'name' => ['old' => 'Premium', 'new' => 'Premium plus'],
        'warning_fraction' => ['old' => '0.50', 'new' => '0.60'],
        'version' => 2,
    ])->and(AuditLog::query()->where('action', 'sla_policy.created')->sole()->changes['targets']['P1'])
        ->toEqual(['first_response_minutes' => 10, 'resolution_minutes' => 60]);

    $this->deleteJson('/v1/sla-policies/'.$entry->subject_id)->assertNoContent();
    expect(AuditLog::query()->where('action', 'sla_policy.deleted')->sole()->changes['name'])->toBe('Premium plus');
});
