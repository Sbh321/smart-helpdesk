<?php

declare(strict_types=1);

use App\Modules\Billing\Actions\ReviewPayment;
use App\Modules\Billing\Console\RemindSubscriptions;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Notifications\SubscriptionReminder;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../Integrations/IntegrationTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * After its grace period a workspace is read-only (ADR-0025 §6, roadmap M6-03): writes are refused for
 * people and API clients, except leaving, reading, exporting and paying; the session carries the state
 * for the banner; `billing:remind` emails each stage once.
 */

beforeEach(function (): void {
    Notification::fake();
    app(SyncPermissionCatalogue::class)();
    $this->clock = new FrozenClock('2026-10-20 12:00:00');
    $this->app->instance(Clock::class, $this->clock);
    $this->standard = Plan::query()->where('code', 'standard')->sole();
    $this->acme = createTenant('acme');
    // Ended on 10 October: grace (7 days) until the 17th, read-only since.
    $this->subscription = Subscription::query()->create(['tenant_id' => $this->acme->id, 'plan_id' => $this->standard->id, 'ends_at' => '2026-10-10 12:00:00', 'reminders' => []]);
    [$this->contact, $this->category] = ticketPrerequisites($this->acme);
});

function ticketBody(): array
{
    return ['title' => 'Printer offline', 'description' => 'The second floor printer is offline.', 'contact_id' => test()->contact->id, 'category_id' => test()->category->id, 'impact' => 2, 'urgency' => 2];
}

it('refuses writes in a read-only workspace and says why, but keeps reads, sign-out and preferences', function (): void {
    actingAsRole($this->acme, 'owner');

    $this->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.tenant.subscription.state', 'expired')
        ->assertJsonPath('data.tenant.subscription.read_only', true);
    $this->getJson('/v1/tickets')->assertOk();
    $this->postJson('/v1/tickets', ticketBody())->assertForbidden()->assertJsonPath('code', 'workspace_read_only');
    $this->patchJson('/v1/me/preferences', ['theme' => 'dark'])->assertOk();
    $this->postJson('/v1/auth/logout')->assertNoContent();
});

it('lets a read-only workspace upload a receipt and pay', function (): void {
    actingAsRole($this->acme, 'owner');

    $this->postJson('/v1/media/intent', ['filename' => 'receipt.png', 'size' => 2048, 'mime' => 'image/png', 'purpose' => 'receipt'])->assertCreated();
    $receipt = MediaItem::factory()->forTenant($this->acme)->ready()->create(['mime_type' => 'image/png', 'name' => 'receipt.png']);
    $this->postJson('/v1/billing/payments', [
        'plan_id' => $this->standard->id, 'periods' => 1, 'amount_minor' => 250000, 'paid_on' => '2026-10-20',
        'method' => 'wallet', 'receipt_media_id' => $receipt->id,
    ])->assertCreated();
});

it('keeps a workspace in grace fully writable', function (): void {
    $this->clock->set('2026-10-15 12:00:00');
    actingAsRole($this->acme, 'owner');

    $this->getJson('/v1/me')->assertOk()->assertJsonPath('data.tenant.subscription.state', 'grace')->assertJsonPath('data.tenant.subscription.days_left', 2);
    $this->postJson('/v1/tickets', ticketBody())->assertCreated();
});

it('refuses API clients in a read-only workspace too', function (): void {
    tenancy()->initialize($this->acme);
    $token = issueToken(createApiClient($this->acme, ['tickets:read', 'tickets:write']), 'tickets:read tickets:write');

    $this->withToken($token)->getJson('/v1/tickets')->assertOk();
    $this->withToken($token)->postJson('/v1/tickets', ticketBody())->assertForbidden()->assertJsonPath('code', 'workspace_read_only');
});

it('opens the workspace again as soon as a payment is approved', function (): void {
    actingAsRole($this->acme, 'owner');
    $receipt = MediaItem::factory()->forTenant($this->acme)->ready()->create(['mime_type' => 'image/png']);
    $id = $this->postJson('/v1/billing/payments', [
        'plan_id' => $this->standard->id, 'periods' => 1, 'amount_minor' => 250000, 'paid_on' => '2026-10-20',
        'method' => 'bank_transfer', 'receipt_media_id' => $receipt->id,
    ])->json('data.id');

    // Approved through the action: the console's session settings would linger in this test's app
    // (PHP-FPM starts every real request afresh).
    app(ReviewPayment::class)->approve(SubscriptionPayment::query()->findOrFail($id), (string) Str::uuid7());

    $this->postJson('/v1/tickets', ticketBody())->assertCreated();
});

it('chooses the reminder that applies now', function (SubscriptionState $state, ?int $daysLeft, ?string $expected): void {
    expect(RemindSubscriptions::reminderFor($state, $daysLeft))->toBe($expected);
})->with([
    'eight days left' => [SubscriptionState::Active, 8, null],
    'seven days left' => [SubscriptionState::Trialing, 7, 'ends-in-7'],
    'five days left' => [SubscriptionState::Active, 5, 'ends-in-7'],
    'three days left' => [SubscriptionState::Active, 3, 'ends-in-3'],
    'one day left' => [SubscriptionState::Trialing, 1, 'ends-in-1'],
    'in grace' => [SubscriptionState::Grace, 4, 'grace'],
    'expired' => [SubscriptionState::Expired, null, 'read-only'],
    'unmanaged' => [SubscriptionState::None, null, null],
]);

it('emails each reminder once, and never a suspended workspace', function (): void {
    $owner = actingAsRole($this->acme, 'owner');
    tenancy()->end();
    $globex = createTenant('globex', ['status' => TenantStatus::Suspended]);
    Subscription::query()->create(['tenant_id' => $globex->id, 'plan_id' => $this->standard->id, 'ends_at' => '2026-10-10 12:00:00', 'reminders' => []]);

    $this->artisan('billing:remind')->assertSuccessful();
    $this->artisan('billing:remind')->assertSuccessful();

    Notification::assertSentToTimes($owner, SubscriptionReminder::class, 1);
    expect($this->subscription->fresh()?->reminders)->toBe(['read-only'])
        ->and(Subscription::forTenant($globex->id)?->reminders)->toBe([]);

    // A new end date starts the reminders again.
    Subscription::query()->whereKey($this->subscription->id)->update(['ends_at' => '2026-10-23 12:00:00', 'reminders' => '[]']);
    $this->artisan('billing:remind')->assertSuccessful();
    expect($this->subscription->fresh()?->reminders)->toBe(['ends-in-3']);
});
