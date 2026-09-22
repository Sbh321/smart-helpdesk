<?php

declare(strict_types=1);

use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// RPT-E01 Email channel (M3-19): inbound mail of the workspace by outcome; platform rows never count.

beforeEach(function (): void {
    $this->app->instance(Clock::class, new FrozenClock('2026-09-22 10:00:00'));
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $row = fn (?string $tenantId, string $state, ?string $reason, string $at): array => [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'message_id' => Str::random(12).'@x.test', 'state' => $state,
        'reason' => $reason, 'route' => $state === 'ticket' ? 'intake' : 'plus_address',
        'processed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
    ];
    $this->acme->run(fn () => DB::table('inbound_emails')->insert([
        $row($this->acme->id, 'comment', null, '2026-09-20 09:00:00'),
        $row($this->acme->id, 'comment', null, '2026-09-21 09:00:00'),
        $row($this->acme->id, 'ticket', null, '2026-09-21 10:00:00'),
        $row($this->acme->id, 'ignored', 'auto_reply', '2026-09-21 11:00:00'),
        $row($this->acme->id, 'rejected', 'sender_not_allowed', '2026-09-21 12:00:00'),
        $row($this->acme->id, 'comment', null, '2026-08-01 09:00:00'), // outside the period
    ]));
    $this->globex->run(fn () => DB::table('inbound_emails')->insert($row($this->globex->id, 'comment', null, '2026-09-21 09:00:00')));
    DB::table('inbound_emails')->insert($row(null, 'unrouted', 'no_route', '2026-09-21 09:00:00'));
});

it('counts the workspace\'s inbound mail by outcome in the period (RPT-E01)', function (): void {
    actingAsRole($this->acme, 'admin');

    $data = $this->postJson('/v1/reports/rpt-e01/run', ['from' => '2026-09-15', 'to' => '2026-09-22'])->assertOk()->json('data');

    expect($data['totals'])->toBe(['messages' => 5, 'comments' => 2, 'tickets' => 1, 'ignored' => 1, 'rejected' => 1])
        ->and(array_column($data['rows'], 'key'))->toBe(['comment', 'ignored', 'rejected', 'ticket'])
        ->and($data['rows'][0]['label'])->toBe('Reply added');

    $byReason = $this->postJson('/v1/reports/rpt-e01/run', ['from' => '2026-09-15', 'to' => '2026-09-22', 'group' => 'reason'])->assertOk()->json('data.rows');
    expect(array_column($byReason, 'label'))->toContain('Automatic reply', 'Sender not allowed', 'None');
});

it('needs mail.manage', function (): void {
    actingAsRole($this->acme, 'manager');

    $this->postJson('/v1/reports/rpt-e01/run')->assertForbidden();
    expect($this->getJson('/v1/reports')->json('data.*.key'))->not->toContain('rpt-e01');
});
