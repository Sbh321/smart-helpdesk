<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Notifications\PaymentApproved;
use App\Modules\Billing\Notifications\PaymentRejected;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Platform\Notifications\PaymentToReview;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * Receipt payments (ADR-0025 §3, §4, roadmap M6-02): a workspace sends a payment with its receipt,
 * a platform admin approves (the subscription moves on) or rejects it, or records one by hand. The
 * billing rows are central, so the workspace endpoints must filter by workspace themselves.
 */

beforeEach(function (): void {
    Notification::fake();
    app(SyncPermissionCatalogue::class)();
    $this->clock = new FrozenClock('2026-10-01 12:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->standard = Plan::query()->where('code', 'standard')->sole();
    $this->trial = Plan::query()->where('code', 'trial')->sole();
    $this->acme = createTenant('acme');
    Subscription::query()->create(['tenant_id' => $this->acme->id, 'plan_id' => $this->trial->id, 'ends_at' => '2026-10-10 12:00:00', 'reminders' => []]);
});

function receiptIn(Tenant $tenant, string $mime = 'image/png'): MediaItem
{
    return MediaItem::factory()->forTenant($tenant)->ready()->create(['mime_type' => $mime, 'name' => 'receipt.'.($mime === 'application/pdf' ? 'pdf' : 'png')]);
}

/** @return array<string, mixed> */
function paymentInput(Plan $plan, MediaItem $receipt, array $overrides = []): array
{
    return [
        'plan_id' => $plan->id, 'periods' => 3, 'amount_minor' => 750000, 'paid_on' => '2026-09-30',
        'method' => 'bank_transfer', 'reference' => 'TRX-1001', 'receipt_media_id' => $receipt->id, ...$overrides,
    ];
}

function submitAsOwner(Tenant $tenant, array $input): TestResponse
{
    actingAsRole($tenant, 'owner');

    return test()->postJson('/v1/billing/payments', $input);
}

it('shows the workspace its subscription, the paid plans on offer and its payments, to billing people only', function (): void {
    actingAsRole($this->acme, 'owner');
    $this->getJson('/v1/billing')->assertOk()
        ->assertJsonPath('data.subscription.state', 'trialing')
        ->assertJsonPath('data.subscription.days_left', 9)
        ->assertJsonPath('data.plans.0.code', 'standard')
        ->assertJsonCount(1, 'data.plans')
        ->assertJsonPath('data.payments', [])
        ->assertJsonPath('data.grace_days', 7);

    actingAsRole($this->acme, 'agent');
    $this->getJson('/v1/billing')->assertForbidden();
});

it('takes a payment with its receipt, links the receipt and tells the platform admins', function (): void {
    $admin = actingAsPlatformAdmin();
    $receipt = receiptIn($this->acme, 'application/pdf');

    $id = submitAsOwner($this->acme, paymentInput($this->standard, $receipt))->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.expected_minor', 750000)
        ->assertJsonPath('data.receipt.name', 'receipt.pdf')
        ->json('data.id');

    $this->acme->run(fn () => expect(Mediable::query()->where('mediable_type', 'subscription_payment')->where('mediable_id', $id)->where('media_item_id', $receipt->id)->exists())->toBeTrue());
    Notification::assertSentTo($admin, PaymentToReview::class);
    $this->acme->run(fn () => expect(AuditLog::query()->where('action', 'payment.submitted')->count())->toBe(1));
    $this->getJson('/v1/billing')->assertOk()->assertJsonPath('data.payments.0.id', $id);
});

it('refuses a payment that is not what it should be', function (array $overrides, string $field): void {
    $receipt = receiptIn($this->acme);

    submitAsOwner($this->acme, paymentInput($this->standard, $receipt, $overrides))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'no receipt' => [['receipt_media_id' => null], 'receipt_media_id'],
    'the trial plan' => [['plan_id' => fn () => test()->trial->id], 'plan_id'],
    'a future date' => [['paid_on' => '2026-10-03'], 'paid_on'],
    'no periods' => [['periods' => 0], 'periods'],
    'a method nobody knows' => [['method' => 'crypto'], 'method'],
]);

it('accepts only an image or a PDF of the same workspace as the receipt, and three waiting payments at most', function (): void {
    submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme, 'text/plain')))
        ->assertUnprocessable()->assertJsonValidationErrors(['receipt_media_id']);

    $foreign = receiptIn(createTenant('globex'));
    submitAsOwner($this->acme, paymentInput($this->standard, $foreign))
        ->assertUnprocessable()->assertJsonValidationErrors(['receipt_media_id']);

    foreach (range(1, 3) as $n) {
        submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme)))->assertCreated();
    }
    submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme)))
        ->assertUnprocessable()->assertJsonValidationErrors(['receipt_media_id']);
});

it('never shows one workspace the payments of another', function (): void {
    $globex = createTenant('globex');
    submitAsOwner($globex, paymentInput($this->standard, receiptIn($globex)))->assertCreated();

    actingAsRole($this->acme, 'owner');
    $this->getJson('/v1/billing')->assertOk()->assertJsonPath('data.payments', []);
});

it('approves a payment from the end of the running trial and emails the billing people', function (): void {
    $owner = actingAsRole($this->acme, 'owner');
    $id = $this->postJson('/v1/billing/payments', paymentInput($this->standard, receiptIn($this->acme)))->json('data.id');

    actingAsPlatformAdmin();
    $this->postJson(onPlatform("payments/{$id}/approve"))->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.period_starts_at', '2026-10-10T12:00:00+00:00')
        ->assertJsonPath('data.period_ends_at', '2027-01-10T12:00:00+00:00')
        ->assertJsonPath('data.workspace.slug', 'acme');

    $subscription = Subscription::forTenant($this->acme->id);
    expect($subscription?->plan->code)->toBe('standard')
        ->and($subscription?->ends_at->toDateTimeString())->toBe('2027-01-10 12:00:00');
    Notification::assertSentTo($owner, PaymentApproved::class);

    $this->postJson(onPlatform("payments/{$id}/approve"))->assertConflict()->assertJsonPath('code', 'already_reviewed');
    $this->postJson(onPlatform("payments/{$id}/reject"), ['reason' => 'Too late now'])->assertConflict();
});

it('extends a lapsed subscription from today', function (): void {
    Subscription::query()->where('tenant_id', $this->acme->id)->update(['ends_at' => '2026-08-01 00:00:00']);
    $id = submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme), ['periods' => 1]))->json('data.id');

    actingAsPlatformAdmin();
    $this->postJson(onPlatform("payments/{$id}/approve"))->assertOk()
        ->assertJsonPath('data.period_starts_at', '2026-10-01T12:00:00+00:00')
        ->assertJsonPath('data.period_ends_at', '2026-11-01T12:00:00+00:00');
});

it('rejects a payment with a reason and leaves the subscription alone', function (): void {
    $owner = actingAsRole($this->acme, 'owner');
    $id = $this->postJson('/v1/billing/payments', paymentInput($this->standard, receiptIn($this->acme)))->json('data.id');

    actingAsPlatformAdmin();
    $this->postJson(onPlatform("payments/{$id}/reject"), [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson(onPlatform("payments/{$id}/reject"), ['reason' => 'The amount on the receipt is NPR 2,500, not 7,500.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');

    expect(Subscription::forTenant($this->acme->id)?->plan->code)->toBe('trial');
    Notification::assertSentTo($owner, PaymentRejected::class);
    expect(tenancy()->central(fn () => AuditLog::query()->whereNull('tenant_id')->where('action', 'payment.rejected')->count()))->toBe(1);
});

it('lists the review queue oldest first and opens a receipt', function (): void {
    $first = submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme)))->json('data.id');
    $this->clock->set('2026-10-01 13:00:00');
    $second = submitAsOwner($this->acme, paymentInput($this->standard, receiptIn($this->acme)))->json('data.id');

    actingAsPlatformAdmin();
    $ids = collect($this->getJson(onPlatform('payments?status=pending'))->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$first, $second]);

    $this->get(onPlatform("payments/{$first}/receipt"))->assertRedirect();
});

it('records a payment for a workspace, approved at once', function (): void {
    actingAsPlatformAdmin();

    $this->postJson(onPlatform("tenants/{$this->acme->id}/payments"), [
        'plan_id' => $this->standard->id, 'periods' => 12, 'amount_minor' => 3000000, 'paid_on' => '2026-10-01', 'method' => 'cash',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.recorded_by_platform', true)
        ->assertJsonPath('data.receipt', null);

    expect(Subscription::forTenant($this->acme->id)?->ends_at->toDateTimeString())->toBe('2027-10-10 12:00:00')
        ->and(SubscriptionPayment::query()->sole()->recorded_by_platform_user_id)->not->toBeNull();
});

it('keeps a receipt to the billing people, and never purges it while its payment exists', function (): void {
    $receipt = receiptIn($this->acme);
    submitAsOwner($this->acme, paymentInput($this->standard, $receipt))->assertCreated();

    actingAsRole($this->acme, 'admin');
    $this->postJson("/v1/media/{$receipt->id}/trash")->assertOk();
    $this->deleteJson("/v1/media/{$receipt->id}")->assertConflict()->assertJsonPath('code', 'in_use');
    $this->postJson("/v1/media/{$receipt->id}/restore")->assertOk();

    actingAsRole($this->acme, 'manager');
    $this->getJson("/v1/media/{$receipt->id}/download")->assertForbidden();
});
