<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\SubscriptionStateSql;
use App\Modules\Billing\Support\SubscriptionStatus;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Plans and subscriptions (ADR-0025 §1, §2, roadmap M6-01): the derived state at its boundaries, the
 * same in PHP and SQL; provisioning on the trial plan; the console's plan and subscription endpoints.
 */

beforeEach(function (): void {
    Notification::fake();
    $this->clock = new FrozenClock('2026-10-01 12:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->trial = Plan::query()->where('code', 'trial')->sole();
    $this->standard = Plan::query()->where('code', 'standard')->sole();
});

function subscribe(Tenant $tenant, Plan $plan, string $endsAt): Subscription
{
    return Subscription::query()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'ends_at' => $endsAt, 'reminders' => []]);
}

/** The state the SQL twin reads for one workspace at a moment. */
function sqlState(Tenant $tenant, CarbonImmutable $now, int $graceDays): string
{
    [$sql, $bindings] = SubscriptionStateSql::expression($now, $graceDays);
    $query = DB::table('tenants')->where('tenants.id', $tenant->id);
    SubscriptionStateSql::joinOnto($query);

    return (string) $query->selectRaw("({$sql}) AS state", $bindings)->value('state');
}

it('derives the state at its boundaries, the same in PHP and in SQL', function (string $plan, string $at, SubscriptionState $expected, ?int $daysLeft): void {
    $tenant = createTenant('acme');
    $subscription = subscribe($tenant, $plan === 'trial' ? $this->trial : $this->standard, '2026-10-10 12:00:00');
    $now = CarbonImmutable::parse($at);

    $status = SubscriptionStatus::of($subscription->fresh(['plan']), $now, 7);

    expect($status->state)->toBe($expected)
        ->and($status->daysLeft())->toBe($daysLeft)
        ->and($status->readOnly())->toBe($expected === SubscriptionState::Expired)
        ->and(sqlState($tenant, $now, 7))->toBe($expected->value);
})->with([
    'trial running' => ['trial', '2026-10-01 12:00:00', SubscriptionState::Trialing, 9],
    'trial, a second before its end' => ['trial', '2026-10-10 11:59:59', SubscriptionState::Trialing, 1],
    'paid running, 30 hours left' => ['paid', '2026-10-09 06:00:00', SubscriptionState::Active, 2],
    'grace from the end itself' => ['paid', '2026-10-10 12:00:00', SubscriptionState::Grace, 7],
    'grace, a second before it ends' => ['trial', '2026-10-17 11:59:59', SubscriptionState::Grace, 1],
    'expired when grace ends' => ['paid', '2026-10-17 12:00:00', SubscriptionState::Expired, null],
]);

it('treats a workspace without a subscription as unmanaged in both readings', function (): void {
    $tenant = createTenant('acme');
    $now = $this->clock->now();

    expect(SubscriptionStatus::of(null, $now, 7)->state)->toBe(SubscriptionState::None)
        ->and(SubscriptionStatus::of(null, $now, 7)->readOnly())->toBeFalse()
        ->and(sqlState($tenant, $now, 7))->toBe('none');
});

it('starts a provisioned workspace on the trial plan, or on the plan and periods given', function (): void {
    $provision = app(ProvisionTenant::class);

    $trial = $provision('acme', 'Acme', 'owner@acme.test')['tenant'];
    $paid = $provision('globex', 'Globex', 'owner@globex.test', plan: $this->standard, periods: 3)['tenant'];

    expect(Subscription::forTenant($trial->id)?->plan->code)->toBe('trial')
        ->and(Subscription::forTenant($trial->id)?->ends_at->toDateTimeString())->toBe('2026-10-15 12:00:00')
        ->and(Subscription::forTenant($paid->id)?->ends_at->toDateTimeString())->toBe('2027-01-01 12:00:00')
        ->and(AuditLog::query()->where('action', 'subscription.started')->count())->toBe(2);

    // Provisioning again changes nothing.
    $provision('acme', 'Acme', 'owner@acme.test');
    expect(Subscription::query()->where('tenant_id', $trial->id)->count())->toBe(1);
});

it('lists, creates and updates plans, with one active trial at most', function (): void {
    actingAsPlatformAdmin();
    subscribe(createTenant('acme'), $this->standard, '2026-12-01');

    $this->getJson(onPlatform('plans'))->assertOk()
        ->assertJsonPath('data.0.code', 'trial')
        ->assertJsonPath('data.1.code', 'standard')
        ->assertJsonPath('data.1.subscriptions_count', 1)
        ->assertJsonPath('data.1.price_minor', 250000);

    $yearly = $this->postJson(onPlatform('plans'), [
        'code' => 'standard-yearly', 'kind' => 'paid', 'name' => 'Standard yearly', 'price_minor' => 2500000, 'period_months' => 12,
    ])->assertCreated()->assertJsonPath('data.period_months', 12)->json('data.id');

    $this->postJson(onPlatform('plans'), ['code' => 'trial-30', 'kind' => 'trial', 'name' => 'Long trial', 'trial_days' => 30])
        ->assertUnprocessable()->assertJsonValidationErrors(['is_active']);
    $this->postJson(onPlatform('plans'), ['code' => 'free', 'kind' => 'paid', 'name' => 'Free', 'price_minor' => 0, 'period_months' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['price_minor']);
    $this->postJson(onPlatform('plans'), ['code' => 'odd', 'kind' => 'trial', 'name' => 'Odd', 'trial_days' => 7, 'period_months' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['period_months']);

    $this->patchJson(onPlatform("plans/{$yearly}"), ['name' => 'Annual', 'is_active' => false, 'code' => 'renamed'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->patchJson(onPlatform("plans/{$yearly}"), ['name' => 'Annual', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.name', 'Annual')->assertJsonPath('data.is_active', false);

    expect(AuditLog::query()->whereIn('action', ['plan.created', 'plan.updated'])->count())->toBe(2);
});

it('sets a workspace subscription and filters workspaces by state', function (): void {
    actingAsPlatformAdmin();
    $acme = createTenant('acme');
    $globex = createTenant('globex');
    createTenant('initech');
    subscribe($acme, $this->trial, '2026-10-05');
    subscribe($globex, $this->standard, '2026-09-20');

    $this->getJson(onPlatform("tenants/{$acme->id}/subscription"))->assertOk()
        ->assertJsonPath('data.state', 'trialing')->assertJsonPath('data.plan.code', 'trial');

    $this->putJson(onPlatform("tenants/{$acme->id}/subscription"), ['plan_id' => $this->standard->id, 'ends_at' => '2027-10-01T00:00:00Z'])
        ->assertOk()->assertJsonPath('data.state', 'active')->assertJsonPath('data.plan.code', 'standard');
    expect(AuditLog::query()->where('action', 'subscription.changed')->sole()->changes['before']['plan'])->toBe('trial');

    $slugs = fn (string $filter): array => collect($this->getJson(onPlatform("tenants?subscription={$filter}"))->assertOk()->json('data'))->pluck('slug')->all();
    expect($slugs('active'))->toBe(['acme'])
        ->and($slugs('expired'))->toBe(['globex'])
        ->and($slugs('none'))->toBe(['initech'])
        ->and($slugs('grace,trialing'))->toBe([]);

    $states = collect($this->getJson(onPlatform('tenants'))->assertOk()->json('data'))->pluck('subscription.state', 'slug')->all();
    expect($states)->toEqual(['acme' => 'active', 'globex' => 'expired', 'initech' => 'none']);
});
