<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// Settings → Users: /v1/users (docs/07-api/conventions.md §Users, security.md §Role assignment).

beforeEach(function (): void {
    $this->clock = new FrozenClock('2026-09-21 09:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = createTenant('people', ['timezone' => 'UTC']);
    $this->owner = actingAsRole($this->tenant, 'owner');
    $this->owner->forceFill(['name' => 'Zara Owner'])->save(); // sorts last in the list tests
    $this->member = function (string $role, array $attributes = []): User {
        $user = createTenantUser($this->tenant, $attributes);
        $this->tenant->run(function () use ($user, $role): void {
            setPermissionsTeamId($this->tenant->getTenantKey());
            $user->syncRoles([$role]);
        });

        return $user;
    };
    $this->actAs = function (User $user): void {
        actingAsTenantUser($this->tenant, $user);
        tenancy()->initialize($this->tenant);
    };
    tenancy()->initialize($this->tenant);
});

function pendingUser(Tenant $tenant, string $email, array $roles, string $expiresAt = '2026-09-23 09:00:00'): User
{
    $user = createTenantUser($tenant, ['email' => $email, 'password' => null]);
    $tenant->run(fn () => Invitation::query()->create([
        'user_id' => $user->id,
        'token_hash' => Invitation::hashToken('token-'.$email),
        'role_names' => $roles,
        'expires_at' => $expiresAt,
    ]));

    return $user;
}

it('invites a user who stays invited, with the roles on the invitation, until they accept', function (): void {
    Notification::fake();

    $id = $this->postJson('/v1/users/invitations', ['name' => ' Chen Wei ', 'email' => 'Chen@People.Test', 'roles' => ['agent']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Chen Wei')
        ->assertJsonPath('data.email', 'chen@people.test')
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.roles', ['agent'])
        ->assertJsonPath('data.invitation_expires_at', '2026-09-23T09:00:00Z')
        ->assertJsonPath('data.invitation_expired', false)
        ->json('data.id');

    $user = User::query()->findOrFail($id);
    expect($user->password)->toBeNull()
        ->and($user->getRoleNames()->all())->toBe([])
        ->and($user->latestInvitation?->invited_by_user_id)->toBe($this->owner->id);
    Notification::assertSentTo($user, UserInvitation::class);
    expect(AuditLog::query()->where('action', 'user.invited')->sole()->changes)->toEqual(['email' => 'chen@people.test', 'roles' => ['agent']]);
});

it('refuses an email already in the workspace, whatever the case, and unknown roles', function (array $payload, string $field): void {
    createTenantUser($this->tenant, ['email' => 'taken@people.test']);
    createTenant('elsewhere')->run(fn ($elsewhere) => Role::query()->create(['name' => 'other-workspace-role', 'guard_name' => 'web', 'tenant_id' => $elsewhere->id]));
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/users/invitations', ['name' => 'Someone', 'email' => 'new@people.test', 'roles' => ['agent'], ...$payload])
        ->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
})->with([
    'email taken' => [['email' => 'TAKEN@people.test'], 'email'],
    'no roles' => [['roles' => []], 'roles'],
    'unknown role' => [['roles' => ['wizard']], 'roles.0'],
    'role of another workspace' => [['roles' => ['other-workspace-role']], 'roles.0'],
    'bad email' => [['email' => 'not-an-email'], 'email'],
]);

it('lets the same email join another workspace', function (): void {
    Notification::fake();
    createTenantUser(createTenant('neighbour'), ['email' => 'shared@people.test']);
    tenancy()->initialize($this->tenant);

    $this->postJson('/v1/users/invitations', ['name' => 'Shared', 'email' => 'shared@people.test', 'roles' => ['agent']])->assertCreated();
});

it('gives the invited roles on acceptance', function (): void {
    Notification::fake();
    $id = $this->postJson('/v1/users/invitations', ['name' => 'Chen', 'email' => 'chen@people.test', 'roles' => ['manager']])->json('data.id');
    $token = null;
    Notification::assertSentTo(User::query()->findOrFail($id), UserInvitation::class, function (UserInvitation $mail) use (&$token): bool {
        $token = (fn () => $this->token)->call($mail);

        return true;
    });

    auth()->forgetGuards();
    fromSpaOrigin();
    $this->postJson(onApiHost("/v1/auth/invitations/{$token}/accept"), [
        'workspace' => 'people', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1',
    ])->assertOk();

    actingAsTenantUser($this->tenant, $this->owner);
    tenancy()->initialize($this->tenant);
    $this->getJson("/v1/users/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.roles', ['manager'])
        ->assertJsonPath('data.invitation_expires_at', null);
});

it('lists users with status and role filters, search and sort', function (): void {
    ($this->member)('agent', ['name' => 'Asha Agent', 'email' => 'asha@people.test']);
    ($this->member)('manager', ['name' => 'Milan Manager', 'email' => 'milan@people.test']);
    ($this->member)('agent', ['name' => 'Dipesh Disabled', 'email' => 'dipesh@people.test', 'is_active' => false]);
    pendingUser($this->tenant, 'ira@people.test', ['agent'])->forceFill(['name' => 'Ira Invited'])->save();
    createTenantUser(createTenant('strangers'), ['name' => 'Aaron Stranger']);
    tenancy()->initialize($this->tenant);

    $names = fn (string $query): array => array_column($this->getJson('/v1/users'.$query)->assertOk()->json('data'), 'name');

    expect($names(''))->toBe(['Asha Agent', 'Dipesh Disabled', 'Ira Invited', 'Milan Manager', $this->owner->name])
        ->and($names('?filter[status]=invited'))->toBe(['Ira Invited'])
        ->and($names('?filter[status]=disabled'))->toBe(['Dipesh Disabled'])
        ->and($names('?filter[status]=active,invited&filter[role]=agent'))->toBe(['Asha Agent', 'Ira Invited'])
        ->and($names('?search=MILAN'))->toBe(['Milan Manager'])
        ->and($names('?sort=-name&per_page=2'))->toBe([$this->owner->name, 'Milan Manager']);

    $this->getJson('/v1/users?filter[status]=gone')->assertStatus(422);
});

it('changes roles and names, with an audit entry, and on a pending invitation too', function (): void {
    $asha = ($this->member)('agent', ['name' => 'Asha']);
    $ira = pendingUser($this->tenant, 'ira@people.test', ['agent']);

    $this->patchJson("/v1/users/{$asha->id}", ['name' => 'Asha Karki', 'roles' => ['manager']])->assertOk()
        ->assertJsonPath('data.name', 'Asha Karki')
        ->assertJsonPath('data.roles', ['manager']);
    $this->patchJson("/v1/users/{$ira->id}", ['roles' => ['manager', 'agent']])->assertOk()
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.roles', ['manager', 'agent']);

    expect($ira->latestInvitation()->sole()->role_names)->toBe(['manager', 'agent'])
        ->and($ira->refresh()->getRoleNames()->all())->toBe([]);
    $audit = AuditLog::query()->where('action', 'user.role_changed')->orderBy('created_at')->first();
    expect($audit?->changes)->toEqual(['old' => ['roles' => ['agent']], 'new' => ['roles' => ['manager']]]);
});

it('never lets an admin hand out or take away the owner role', function (): void {
    $admin = ($this->member)('admin');
    $agent = ($this->member)('agent');
    ($this->actAs)($admin);

    $this->patchJson("/v1/users/{$admin->id}", ['roles' => ['owner']])->assertForbidden()
        ->assertJsonPath('code', 'forbidden')->assertJsonPath('meta.roles', ['owner']);
    $this->patchJson("/v1/users/{$agent->id}", ['roles' => ['owner']])->assertForbidden();
    $this->patchJson("/v1/users/{$this->owner->id}", ['roles' => ['admin']])->assertForbidden();
    $this->postJson("/v1/users/{$this->owner->id}/disable")->assertForbidden();
    $this->postJson('/v1/users/invitations', ['name' => 'Boss', 'email' => 'boss@people.test', 'roles' => ['owner']])->assertForbidden();

    $this->patchJson("/v1/users/{$agent->id}", ['roles' => ['manager']])->assertOk();
});

it('never lets a user give a custom role with permissions they lack', function (): void {
    $this->tenant->run(function (): void {
        Role::query()->create(['name' => 'auditor', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])
            ->givePermissionTo(['audit.view', 'tickets.view']);
    });
    $limited = createTenantUser($this->tenant);
    $this->tenant->run(function () use ($limited): void {
        setPermissionsTeamId($this->tenant->getTenantKey());
        $limited->givePermissionTo(['users.manage', 'tickets.view']);
    });
    $agent = ($this->member)('agent');
    ($this->actAs)($limited);

    $this->patchJson("/v1/users/{$agent->id}", ['roles' => ['agent', 'auditor']])->assertForbidden()
        ->assertJsonPath('meta.roles', ['auditor']);
});

it('keeps the last active owner', function (): void {
    $this->patchJson("/v1/users/{$this->owner->id}", ['roles' => ['admin']])->assertStatus(422)->assertJsonPath('code', 'last_owner');

    $second = ($this->member)('owner');
    $this->patchJson("/v1/users/{$second->id}", ['roles' => ['admin']])->assertOk();
});

it('disables a user at once: sessions and tokens end, sign-in fails, and enabling brings them back', function (): void {
    $asha = ($this->member)('agent', ['email' => 'asha@people.test', 'password' => 'a-long-password-1']);
    $asha->createToken('cli');
    DB::table('sessions')->insert(['id' => 'asha-session', 'user_id' => $asha->id, 'tenant_id' => $this->tenant->id, 'payload' => '', 'last_activity' => 0]);

    $this->postJson("/v1/users/{$asha->id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');

    $asha->refresh();
    expect($asha->is_active)->toBeFalse()
        ->and($asha->disabled_at?->toDateTimeString())->toBe('2026-09-21 09:00:00')
        ->and(DB::table('sessions')->where('user_id', $asha->id)->exists())->toBeFalse()
        ->and($asha->tokens()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'user.disabled')->exists())->toBeTrue();

    auth()->forgetGuards();
    fromSpaOrigin();
    $this->postJson(onApiHost('/v1/auth/login'), ['workspace' => 'people', 'email' => 'asha@people.test', 'password' => 'a-long-password-1'])
        ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

    actingAsTenantUser($this->tenant, $this->owner);
    tenancy()->initialize($this->tenant);
    $this->postJson("/v1/users/{$asha->id}/enable")->assertOk()->assertJsonPath('data.status', 'active');
    expect($asha->refresh()->disabled_at)->toBeNull();
});

it('refuses to disable your own account', function (): void {
    $this->postJson("/v1/users/{$this->owner->id}/disable")->assertConflict()->assertJsonPath('meta.reason', 'self');
});

it('resends an invitation with a new link and the same roles, but not after acceptance', function (): void {
    Notification::fake();
    $ira = pendingUser($this->tenant, 'ira@people.test', ['manager'], '2026-09-20 09:00:00');
    $this->getJson("/v1/users/{$ira->id}")->assertJsonPath('data.invitation_expired', true);

    $this->postJson("/v1/users/{$ira->id}/invitation")->assertOk()
        ->assertJsonPath('data.invitation_expired', false)
        ->assertJsonPath('data.invitation_expires_at', '2026-09-23T09:00:00Z')
        ->assertJsonPath('data.roles', ['manager']);
    Notification::assertSentTo($ira, UserInvitation::class);
    expect(Invitation::query()->where('user_id', $ira->id)->count())->toBe(2);

    $active = ($this->member)('agent', ['password' => 'a-long-password-1']);
    $this->postJson("/v1/users/{$active->id}/invitation")->assertConflict()->assertJsonPath('meta.reason', 'already_accepted');
});

it('stops the previous link working when a new one is sent', function (): void {
    Notification::fake();
    $ira = pendingUser($this->tenant, 'ira@people.test', ['agent']);

    $this->postJson("/v1/users/{$ira->id}/invitation")->assertOk();

    $old = Invitation::query()->where('token_hash', Invitation::hashToken('token-ira@people.test'))->sole();
    expect($old->isPending($this->clock->now()))->toBeFalse();
});

it('answers 404 for a user of another workspace', function (): void {
    $stranger = createTenantUser(createTenant('far-away'));
    tenancy()->initialize($this->tenant);

    $this->getJson("/v1/users/{$stranger->id}")->assertNotFound();
    $this->patchJson("/v1/users/{$stranger->id}", ['name' => 'Taken over'])->assertNotFound();
    $this->postJson("/v1/users/{$stranger->id}/disable")->assertNotFound();
});

it('requires users.manage', function (string $role, int $status): void {
    $user = ($this->member)($role);
    $target = ($this->member)('agent');
    ($this->actAs)($user);

    $this->getJson('/v1/users')->assertStatus($status);
    $this->getJson("/v1/users/{$target->id}")->assertStatus($status);
})->with([['agent', 403], ['manager', 403], ['developer', 403], ['admin', 200]]);
