<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantCounter;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->provision = app(ProvisionTenant::class);
});

it('creates the workspace with its counter, domain and invited owner', function (): void {
    $result = ($this->provision)('acme', 'Acme Corp', 'priya@acme.test', 'Priya', 'Asia/Kathmandu');
    $tenant = $result['tenant'];

    expect($tenant->slug)->toBe('acme')
        ->and($tenant->status->value)->toBe('active')
        ->and($tenant->timezone)->toBe('Asia/Kathmandu')
        ->and(Subscription::forTenant($tenant->id)?->plan->code)->toBe('trial')
        ->and($tenant->placement)->toBe('shared')
        ->and(TenantCounter::query()->where('tenant_id', $tenant->id)->value('next_ticket_number'))->toBe(1)
        ->and($tenant->domains()->where('is_primary', true)->value('domain'))->toBe('app.shp.localhost/acme')
        ->and($result['owner']?->email)->toBe('priya@acme.test')
        ->and($result['invitation_token'])->toBeString();

    tenancy()->initialize($tenant);
    $invitation = Invitation::query()->sole();
    expect($invitation->user_id)->toBe($result['owner']?->id)
        ->and($invitation->role_names)->toBe(['owner'])
        ->and($invitation->accepted_at)->toBeNull()
        ->and($result['owner']?->password)->toBeNull();
    tenancy()->end();

    Notification::assertSentTo($result['owner'], UserInvitation::class);
    expect(AuditLog::query()->where('action', 'tenant.created')->sole()->tenant_id)->toBeNull();
});

it('is idempotent for the same slug', function (): void {
    $first = ($this->provision)('acme', 'Acme Corp', 'priya@acme.test');
    $second = ($this->provision)('acme', 'Acme Corp', 'priya@acme.test');

    expect($second['tenant']->id)->toBe($first['tenant']->id)
        ->and($second['invitation_token'])->toBeNull()
        ->and(Tenant::query()->count())->toBe(1)
        ->and(TenantCounter::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'tenant.created')->count())->toBe(1);

    tenancy()->initialize($first['tenant']);
    expect(Invitation::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
    tenancy()->end();

    Notification::assertSentTimes(UserInvitation::class, 1);
});

it('creates a workspace without an owner when none is given', function (): void {
    $result = ($this->provision)('acme', 'Acme Corp');

    expect($result['owner'])->toBeNull()
        ->and($result['invitation_token'])->toBeNull();

    Notification::assertNothingSent();
});

it('lowercases the slug', function (): void {
    expect(($this->provision)('ACME', 'Acme Corp')['tenant']->slug)->toBe('acme');
});

it('provisions from the console command and prints the invitation link', function (): void {
    $this->artisan('platform:create-tenant', ['slug' => 'globex', 'name' => 'Globex', '--owner' => 'ops@globex.test'])
        ->assertSuccessful()
        ->expectsOutputToContain('https://app.shp.localhost/globex/accept-invitation?token=');

    expect(Tenant::findBySlug('globex'))->not->toBeNull();
});

it('lets the invited owner accept and sign in', function (): void {
    $result = ($this->provision)('acme', 'Acme Corp', 'priya@acme.test');

    $this->withHeader('Origin', 'https://app.shp.localhost')
        ->postJson("/v1/auth/invitations/{$result['invitation_token']}/accept", [
            'workspace' => 'acme',
            'password' => 'a-good-long-password',
            'password_confirmation' => 'a-good-long-password',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'priya@acme.test');
});
