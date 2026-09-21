<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\Notification;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;

// GET /v1/notifications, read, read-all and the unread count in /v1/me.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 09:00:00'));
    $this->tenant = createTenant('inbox');
    $this->user = actingAsRole($this->tenant, 'agent');
    $this->make = fn (array $attributes = []): Notification => Notification::factory()->forTenant($this->tenant)
        ->create(['notifiable_id' => $this->user->id, ...$attributes]);
});

it('lists my notifications, unread first then newest, and filters unread', function (): void {
    $oldUnread = ($this->make)(['created_at' => '2026-09-20 08:00:00']);
    $read = ($this->make)(['created_at' => '2026-09-21 08:30:00', 'read_at' => '2026-09-21 08:40:00']);
    $newUnread = ($this->make)(['created_at' => '2026-09-21 08:00:00', 'type' => 'sla_breached']);
    Notification::factory()->forTenant($this->tenant)->create(); // somebody else's
    tenancy()->initialize($this->tenant);

    $this->getJson('/v1/notifications')->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.*.id', [$newUnread->id, $oldUnread->id, $read->id])
        ->assertJsonPath('data.0.kind', 'sla_breached')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.0.created_at', '2026-09-21T08:00:00Z')
        ->assertJsonPath('data.0.summary', 'A ticket was assigned to you')
        ->assertJsonPath('data.2.read_at', '2026-09-21T08:40:00Z');

    $this->getJson('/v1/notifications?filter[unread]=true&per_page=1')->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
});

it('marks one as read once, and all as read, and /me counts the unread ones', function (): void {
    $first = ($this->make)();
    ($this->make)();
    ($this->make)();
    tenancy()->initialize($this->tenant);

    $this->getJson('/v1/me')->assertOk()->assertJsonPath('data.unread_notifications', 3);

    $this->postJson("/v1/notifications/{$first->id}/read")->assertOk()->assertJsonPath('data.read_at', '2026-09-21T09:00:00Z');
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 10:00:00'));
    $this->postJson("/v1/notifications/{$first->id}/read")->assertOk()->assertJsonPath('data.read_at', '2026-09-21T09:00:00Z');
    $this->getJson('/v1/me')->assertJsonPath('data.unread_notifications', 2);

    $this->postJson('/v1/notifications/read-all')->assertNoContent();
    $this->getJson('/v1/me')->assertJsonPath('data.unread_notifications', 0);
});

it('never shows or changes notifications of another user or workspace', function (): void {
    $colleagues = Notification::factory()->forTenant($this->tenant)->create();
    $foreign = Notification::factory()->forTenant(createTenant('inbox-other'))->create();
    tenancy()->initialize($this->tenant);

    $this->getJson('/v1/notifications')->assertOk()->assertJsonPath('meta.total', 0);
    $this->postJson("/v1/notifications/{$colleagues->id}/read")->assertNotFound();
    $this->postJson("/v1/notifications/{$foreign->id}/read")->assertNotFound();
    $this->postJson('/v1/notifications/read-all')->assertNoContent();

    expect(Notification::query()->withoutTenancy()->whereNotNull('read_at')->count())->toBe(0);
});

it('requires a session', function (): void {
    auth()->forgetGuards(); // beforeEach signed a user in
    fromSpaOrigin();
    $this->getJson(onApiHost('/v1/notifications'))->assertUnauthorized();
});
