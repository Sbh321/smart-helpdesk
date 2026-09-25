<?php

declare(strict_types=1);

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Platform\Models\WorkspaceSignup;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Cache;

/*
 * The console's settings and dashboard (ADR-0025 §8, §9, roadmap M6-08) on a small known platform.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->clock = new FrozenClock('2026-10-15 12:00:00');
    $this->app->instance(Clock::class, $this->clock);
});

it('reads and changes the platform settings, audited', function (): void {
    actingAsPlatformAdmin();

    $this->getJson(onPlatform('settings'))->assertOk()->assertJsonPath('data', ['signup_enabled' => true, 'grace_days' => 7, 'payment_instructions' => '']);
    $this->patchJson(onPlatform('settings'), ['grace_days' => 61])->assertUnprocessable()->assertJsonValidationErrors(['grace_days']);
    $this->patchJson(onPlatform('settings'), ['signup_enabled' => false, 'grace_days' => 10])->assertOk()
        ->assertJsonPath('data', ['signup_enabled' => false, 'grace_days' => 10, 'payment_instructions' => '']);
    $this->patchJson(onPlatform('settings'), ['payment_instructions' => ' Nabil Bank, account 0123456789 '])->assertOk()
        ->assertJsonPath('data.payment_instructions', 'Nabil Bank, account 0123456789')
        ->assertJsonPath('data.grace_days', 10);
});

it('counts workspaces, people, tickets, revenue and what needs attention', function (): void {
    $trial = Plan::query()->where('code', 'trial')->sole();
    $standard = Plan::query()->where('code', 'standard')->sole();
    $acme = createTenant('acme');
    $globex = createTenant('globex');
    $initech = createTenant('initech', ['status' => TenantStatus::Suspended]);
    Subscription::query()->create(['tenant_id' => $acme->id, 'plan_id' => $standard->id, 'ends_at' => '2027-01-01', 'reminders' => []]);
    Subscription::query()->create(['tenant_id' => $globex->id, 'plan_id' => $trial->id, 'ends_at' => '2026-10-20 12:00:00', 'reminders' => []]);
    WorkspaceSignup::query()->create([
        'name' => 'G', 'email' => 'g@globex.test', 'password' => 'x', 'workspace_name' => 'Globex', 'slug' => 'globex',
        'timezone' => 'UTC', 'token_hash' => str_repeat('a', 64), 'expires_at' => '2026-10-16', 'verified_at' => '2026-10-15', 'tenant_id' => $globex->id,
    ]);
    createTenantUser($acme);
    createTenantUser($acme, ['is_active' => false]);
    createTenantUser($globex);
    Ticket::factory()->forTenant($acme)->count(2)->create();
    $pay = fn (string $status, int $amount, string $paidOn) => SubscriptionPayment::query()->create([
        'tenant_id' => $acme->id, 'plan_id' => $standard->id, 'periods' => 1, 'amount_minor' => $amount, 'currency' => 'NPR',
        'paid_on' => $paidOn, 'method' => 'bank_transfer', 'status' => $status, 'rejection_reason' => $status === 'rejected' ? 'Wrong amount' : null,
    ]);
    $pay('approved', 250000, '2026-10-02');
    $pay('approved', 500000, '2026-09-10');
    $pay('rejected', 999900, '2026-10-03');
    $pay('pending', 250000, '2026-10-14');

    actingAsPlatformAdmin();
    $data = $this->getJson(onPlatform('dashboard'))->assertOk()->json('data');

    expect($data['workspaces'])->toMatchArray(['total' => 3, 'active' => 2, 'suspended' => 1])
        ->and($data['workspaces']['by_subscription'])->toMatchArray(['active' => 1, 'trialing' => 1, 'none' => 0])
        ->and($data['inside'])->toMatchArray(['active_users' => 2, 'tickets_30d' => 2])
        ->and($data['payments']['pending'])->toBe(1)
        ->and($data['revenue'][0])->toMatchArray(['currency' => 'NPR', 'this_month_minor' => 250000, 'last_month_minor' => 500000])
        ->and($data['revenue'][0]['months'])->toHaveCount(12)
        ->and($data['ending_soon'][0])->toMatchArray(['plan' => 'Free trial', 'state' => 'trialing', 'days_left' => 5])
        ->and($data['ending_soon'][0]['workspace']['slug'])->toBe('globex')
        ->and($data['new_workspaces'])->toHaveCount(12)
        ->and(collect($data['recent_workspaces'])->pluck('source', 'slug')->all())->toMatchArray(['globex' => 'signup', 'acme' => 'console']);
    expect(array_sum(array_column($data['new_workspaces'], 'signup')))->toBe(1);
    unset($initech);
});
