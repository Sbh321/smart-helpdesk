<?php

declare(strict_types=1);

use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class AuditSubjectStub extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';
}

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-01 09:00:00'));
});

it('records the acting user, subject, changes and request context', function (): void {
    $userId = (string) Str::uuid7();
    $subject = (new AuditSubjectStub)->forceFill(['id' => (string) Str::uuid7()]);
    $tenant = createTenant('acme');
    $tenantId = $tenant->getKey();

    Route::middleware('api')->post('/v1/test-audit', function () use ($subject, $tenant, $tenantId) {
        tenancy()->initialize($tenant);
        $entry = Audit::record('user.role_changed', $subject, ['role' => ['old' => 'agent', 'new' => 'manager']], $tenantId);

        return ['id' => $entry->id];
    });

    $response = $this->actingAs(new GenericUser(['id' => $userId]))
        ->postJson('/v1/test-audit', [], ['User-Agent' => 'PestBrowser/1.0', 'X-Request-Id' => 'req-12345678'])
        ->assertOk();

    $entry = $tenant->run(fn (): AuditLog => AuditLog::query()->findOrFail($response->json('id')));

    expect($entry->actor_type)->toBe(ActorType::User)
        ->and($entry->actor_id)->toBe($userId)
        ->and($entry->tenant_id)->toBe($tenantId)
        ->and($entry->action)->toBe('user.role_changed')
        ->and($entry->subject_type)->toBe('audit_subject_stub')
        ->and($entry->subject_id)->toBe($subject->getKey())
        ->and($entry->changes)->toEqual(['role' => ['old' => 'agent', 'new' => 'manager']])
        ->and($entry->ip_address)->toBe('127.0.0.1')
        ->and($entry->user_agent)->toBe('PestBrowser/1.0')
        ->and($entry->request_id)->toBe('req-12345678')
        ->and($entry->created_at->toIso8601String())->toBe('2026-09-01T09:00:00+00:00');
});

it('records a system actor when nobody is authenticated', function (): void {
    $entry = Audit::record('tenant.suspended');

    expect($entry->actor_type)->toBe(ActorType::System)
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->tenant_id)->toBeNull()
        ->and($entry->fresh()->changes)->toBe([]);
});

it('accepts an explicit actor for commands and jobs', function (): void {
    $actorId = (string) Str::uuid7();

    $entry = Audit::record('tenant.created', actorType: ActorType::PlatformUser, actorId: $actorId);

    expect($entry->actor_type)->toBe(ActorType::PlatformUser)
        ->and($entry->actor_id)->toBe($actorId);
});

it('refuses to update or delete entries through the model', function (): void {
    $entry = Audit::record('settings.updated');

    expect(fn () => $entry->update(['action' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('is append-only for the runtime database role', function (): void {
    Audit::record('settings.updated');

    expect(DB::connection()->getConfig('username'))->toBe('helpdesk_app');
    DB::table('audit_logs')->update(['action' => 'tampered']);
})->throws(QueryException::class, 'permission denied');

it('rejects unknown actor types in the database', function (): void {
    DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid7(),
        'actor_type' => 'robot',
        'action' => 'x',
    ]);
})->throws(QueryException::class, 'audit_logs_actor_type_check');
