<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Settings\Settings;

// GET/PATCH /v1/settings/email (docs/07-api/conventions.md, docs/04-domain/email.md §Settings → Email; M3-18).

beforeEach(function (): void {
    $this->tenant = createTenant('acme', ['name' => 'Acme']);
    config([
        'helpdesk.hosts.mail' => 'shp.example',
        'helpdesk.mail.mx_host' => 'mail.shp.example',
        'helpdesk.mail.spf_include' => null,
        'helpdesk.mail.dmarc_policy' => 'none',
        'helpdesk.mail.dkim_selector' => 'v1-rsa-20260921',
        'helpdesk.mail.dkim_public_key' => 'MIIBkey',
        'mail.from.address' => 'no-reply@shp.example',
    ]);
});

it('shows the sender identity, intake address and DNS records', function (): void {
    actingAsRole($this->tenant, 'admin');

    $this->getJson('/v1/settings/email')->assertOk()
        ->assertJsonPath('data.sender_name', null)
        ->assertJsonPath('data.default_sender_name', 'Acme Support')
        ->assertJsonPath('data.from', ['name' => 'Acme Support', 'address' => 'support+acme@shp.example'])
        ->assertJsonPath('data.platform_from', ['name' => 'Acme via Smart Helpdesk', 'address' => 'no-reply@shp.example'])
        ->assertJsonPath('data.intake_address', 'support+acme@shp.example')
        ->assertJsonPath('data.reply_to_pattern', 'ticket+<ticket-id>@shp.example')
        ->assertJsonPath('data.mail_domain', 'shp.example')
        ->assertJsonPath('data.version', 0)
        ->assertJsonCount(4, 'data.dns_records')
        ->assertJsonPath('data.dns_records.0', [
            'type' => 'MX', 'name' => 'shp.example', 'value' => '10 mail.shp.example',
            'purpose' => 'Receives replies and new requests for the intake addresses.', 'ready' => true,
        ])
        ->assertJsonPath('data.dns_records.2.name', 'v1-rsa-20260921._domainkey.shp.example')
        ->assertJsonPath('data.dns_records.2.value', 'v=DKIM1; k=rsa; h=sha256; p=MIIBkey');
});

it('says the DKIM record is not ready before mail-init.sh has run', function (): void {
    config(['helpdesk.mail.dkim_selector' => null, 'helpdesk.mail.dkim_public_key' => null]);
    actingAsRole($this->tenant, 'owner');

    $this->getJson('/v1/settings/email')->assertOk()
        ->assertJsonPath('data.dns_records.2.ready', false)
        ->assertJsonPath('data.dns_records.2.name', '<selector>._domainkey.shp.example');
});

it('stores the sender name as a versioned, audited setting and clears it with null', function (): void {
    $user = actingAsRole($this->tenant, 'admin');

    $this->patchJson('/v1/settings/email', ['sender_name' => '  Acme Customer Care '])->assertOk()
        ->assertJsonPath('data.sender_name', 'Acme Customer Care')
        ->assertJsonPath('data.from.name', 'Acme Customer Care')
        ->assertJsonPath('data.from.address', 'support+acme@shp.example')
        ->assertJsonPath('data.version', 1);

    expect(app(Settings::class)->get('email.sender_name'))->toBe('Acme Customer Care');
    $audit = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($audit->actor_id)->toBe($user->id)
        ->and($audit->changes['section'])->toBe('email')
        ->and($audit->changes['old'])->toBe(['sender_name' => null, 'create_contacts' => true, 'match_organisation_domain' => true])
        ->and($audit->changes['new'])->toBe(['sender_name' => 'Acme Customer Care', 'create_contacts' => true, 'match_organisation_domain' => true]);

    $this->patchJson('/v1/settings/email', ['sender_name' => ''])->assertOk()
        ->assertJsonPath('data.sender_name', null)
        ->assertJsonPath('data.from.name', 'Acme Support')
        ->assertJsonPath('data.version', 2);
});

it('refuses a sender name that could inject headers or look like an address', function (string $name): void {
    actingAsRole($this->tenant, 'admin');

    $this->patchJson('/v1/settings/email', ['sender_name' => $name])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['sender_name']]);

    expect(app(Settings::class)->get('email.sender_name'))->toBeNull();
})->with([
    'line break' => ["Acme\r\nBcc: victim@evil.test"],
    'angle brackets' => ['Acme <ceo@acme.test>'],
    'quote' => ['Acme "Support"'],
    'at sign' => ['support@paypal.com'],
    'too long' => [str_repeat('a', 81)],
]);

it('needs mail.manage: agents and managers are refused', function (string $role): void {
    actingAsRole($this->tenant, $role);

    $this->getJson('/v1/settings/email')->assertForbidden();
    $this->patchJson('/v1/settings/email', ['sender_name' => 'Hacked'])->assertForbidden();
})->with(['agent', 'manager']);

it('answers the dedicated endpoint, not the generic settings section, for email', function (): void {
    // settings.manage without mail.manage: the generic /settings/{section} route would let this user in.
    actingAsRole($this->tenant, 'agent');
    $this->tenant->run(function (): void {
        Role::query()->create(['name' => 'settings-only', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id])
            ->givePermissionTo(['settings.manage', 'tickets.view']);
    });
    $user = actingAsTenantUser($this->tenant);
    $this->tenant->run(fn () => $user->syncRoles(['settings-only']));

    $this->getJson('/v1/settings/email')->assertForbidden();
    $this->patchJson('/v1/settings/email', ['sender_name' => 'Sneaky'])->assertForbidden();
    $this->getJson('/v1/settings/general')->assertOk();
});

it('keeps each workspace to its own sender name', function (): void {
    $globex = createTenant('globex', ['name' => 'Globex']);
    actingAsRole($this->tenant, 'admin');
    $this->patchJson('/v1/settings/email', ['sender_name' => 'Acme Care'])->assertOk();

    actingAsRole($globex, 'admin');
    $this->getJson('/v1/settings/email')->assertOk()
        ->assertJsonPath('data.sender_name', null)
        ->assertJsonPath('data.from', ['name' => 'Globex Support', 'address' => 'support+globex@shp.example']);
});
