<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Mail\Contracts\InboundMailbox;
use App\Modules\Mail\Models\InboundEmail;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Tenancy\Settings\Settings;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/InboundEmailTestSupport.php';
require_once __DIR__.'/../Media/MediaTestSupport.php';

// Settings → Email: the inbound log API, the inbound toggles, the rejected-mail notification and
// the mail:fetch-inbound command (docs/04-domain/email.md, M3-19).

beforeEach(function (): void {
    useMediaTestDisk();
    $this->app->instance(Clock::class, new FrozenClock('2026-09-22 10:00:00'));
    config(['helpdesk.hosts.mail' => 'shp.example']);
    app(SyncPermissionCatalogue::class)();
    $this->acme = createTenant('acme', ['name' => 'Acme']);
    $this->globex = createTenant('globex', ['name' => 'Globex']);
    $this->a = inboundWorkspace($this->acme);
    $this->g = inboundWorkspace($this->globex);
});

afterEach(fn () => cleanMediaTestDisk());

describe('inbound log', function (): void {
    it('lists the workspace\'s messages newest first with state, sender, subject, ticket and reason', function (): void {
        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));
        $this->app->instance(Clock::class, new FrozenClock('2026-09-22 10:05:00'));
        processInbound(inboundMessage(['From' => 'Mallory <mallory@evil.test>', 'To' => "ticket+{$this->a['ticket']->id}@shp.example", 'Subject' => 'Close it']));
        processInbound(inboundMessage(['To' => 'nobody@shp.example']));
        processInbound(inboundMessage(['To' => 'support+globex@shp.example']));
        actingAsRole($this->acme, 'admin');

        $response = $this->getJson('/v1/inbound-emails')->assertOk();

        expect($response->json('data.*.state'))->toBe(['rejected', 'comment'])
            ->and($response->json('data.0.reason'))->toBe('sender_not_allowed')
            ->and($response->json('data.0.from'))->toBe(['address' => 'mallory@evil.test', 'name' => 'Mallory'])
            ->and($response->json('data.0.subject'))->toBe('Close it')
            ->and($response->json('data.0.route'))->toBe('plus_address')
            ->and($response->json('data.0.ticket'))->toBe(['id' => $this->a['ticket']->id, 'number' => $this->a['ticket']->number, 'title' => 'Printer offline'])
            ->and($response->json('data.1.to'))->toBe(["ticket+{$this->a['ticket']->id}@shp.example"])
            ->and($response->json('data.1.processed_at'))->toBe('2026-09-22T10:00:00Z')
            ->and($response->json('meta.per_page'))->toBe(25);
    });

    it('filters by state and ticket and pages with a cursor', function (): void {
        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));
        processInbound(inboundEml('auto-reply', $this->a['ticket']->id));
        processInbound(inboundEml('html-only'));
        actingAsRole($this->acme, 'owner');

        expect($this->getJson('/v1/inbound-emails?filter[state]=ignored')->assertOk()->json('data.*.reason'))->toBe(['auto_reply'])
            ->and($this->getJson("/v1/inbound-emails?filter[ticket_id]={$this->a['ticket']->id}")->json('data'))->toHaveCount(2);

        $first = $this->getJson('/v1/inbound-emails?per_page=2')->assertOk();
        $next = $this->getJson('/v1/inbound-emails?per_page=2&cursor='.$first->json('meta.next_cursor'))->assertOk();
        expect($first->json('data'))->toHaveCount(2)->and($next->json('data'))->toHaveCount(1);
    });

    it('refuses unknown filters, page and search', function (string $query): void {
        actingAsRole($this->acme, 'owner');

        $this->getJson("/v1/inbound-emails?{$query}")->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    })->with(['filter[state]=lost', 'filter[ticket_id]=nope', 'page=2', 'search=x', 'sort=subject']);

    it('shows one message with the parsed reply, the text and the headers', function (): void {
        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)));
        actingAsRole($this->acme, 'admin');

        $response = $this->getJson("/v1/inbound-emails/{$row->id}")->assertOk();

        expect($response->json('data.reply_text'))->toBe("Thanks, the printer works again.\n\nAsha")
            ->and($response->json('data.text_body'))->toContain('> We replaced the toner.')
            ->and($response->json('data.headers.message-id'))->toBe(['<CAF+gmail-4411@mail.gmail.test>'])
            ->and($response->json('data.raw_size'))->toBeGreaterThan(0)
            ->and($response->json('data.state'))->toBe('comment');
    });

    it('answers 404 for another workspace\'s message and for a platform row', function (): void {
        $globex = inboundRow(processInbound(inboundMessage(['To' => 'support+globex@shp.example'])));
        $platform = inboundRow(processInbound(inboundMessage(['To' => 'nobody@shp.example'])));
        actingAsRole($this->acme, 'owner');

        $this->getJson("/v1/inbound-emails/{$globex->id}")->assertNotFound();
        $this->getJson("/v1/inbound-emails/{$platform->id}")->assertNotFound();
        expect($this->getJson('/v1/inbound-emails')->json('data'))->toBe([]);
    });

    it('needs mail.manage', function (string $role): void {
        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)));
        actingAsRole($this->acme, $role);

        $this->getJson('/v1/inbound-emails')->assertForbidden();
        $this->getJson("/v1/inbound-emails/{$row->id}")->assertForbidden();
    })->with(['manager', 'agent']);
});

describe('inbound settings', function (): void {
    it('shows and changes the unknown-sender toggles without touching the sender name', function (): void {
        actingAsRole($this->acme, 'admin');
        app(Settings::class)->update('email', ['sender_name' => 'Acme Care']);

        $this->getJson('/v1/settings/email')->assertOk()
            ->assertJsonPath('data.create_contacts', true)
            ->assertJsonPath('data.match_organisation_domain', true);

        $this->patchJson('/v1/settings/email', ['create_contacts' => false])->assertOk()
            ->assertJsonPath('data.create_contacts', false)
            ->assertJsonPath('data.match_organisation_domain', true)
            ->assertJsonPath('data.sender_name', 'Acme Care');

        $this->patchJson('/v1/settings/email', ['match_organisation_domain' => 'maybe'])->assertUnprocessable();
    });
});

describe('rejected mail notification', function (): void {
    it('tells the workspace\'s mail admins and the ticket\'s assignee, in-app only', function (): void {
        $admin = createTenantUser($this->acme, ['name' => 'Meera']);
        $agent = createTenantUser($this->acme, ['name' => 'Chen']);
        $this->acme->run(function () use ($admin, $agent): void {
            $admin->syncRoles(['admin']);
            $agent->syncRoles(['agent']);
        });
        $globexAdmin = createTenantUser($this->globex);
        $this->globex->run(fn () => $globexAdmin->syncRoles(['owner']));

        processInbound(inboundMessage(['From' => 'mallory@evil.test', 'To' => "ticket+{$this->a['ticket']->id}@shp.example"]));
        processInbound(inboundEml('auto-reply', $this->a['ticket']->id));
        processInbound(inboundMessage(['To' => 'nobody@shp.example']));

        $notified = $this->acme->run(fn () => Notification::query()->where('type', 'inbound_email_rejected')->get());
        expect($notified->pluck('notifiable_id')->all())->toBe([$admin->id])
            ->and($notified->first()->data['summary'])->toBe("An email to ticket #{$this->a['ticket']->number} was rejected: the sender is not a contact of the ticket")
            ->and($this->globex->run(fn () => Notification::query()->count()))->toBe(0);
    });
});

describe('mail:fetch-inbound', function (): void {
    beforeEach(function (): void {
        $this->mailbox = new FakeInboundMailbox;
        $this->app->instance(InboundMailbox::class, $this->mailbox);
        config(['helpdesk.mail.inbound.enabled' => true]);
    });

    it('does nothing while inbound email is off', function (): void {
        config(['helpdesk.mail.inbound.enabled' => false]);
        $this->mailbox->add(inboundEml('gmail-reply', $this->a['ticket']->id));

        expect(Artisan::call('mail:fetch-inbound'))->toBe(0)
            ->and($this->mailbox->processed)->toBe([]);
    });

    it('processes the Inbox and Junk folders and moves every handled message to Processed', function (): void {
        $this->mailbox->add(inboundEml('gmail-reply', $this->a['ticket']->id));
        $this->mailbox->add(inboundEml('plain-new-ticket'), 'Junk Mail');
        $this->mailbox->add(inboundEml('bounce'));
        $this->mailbox->add(inboundEml('gmail-reply', $this->a['ticket']->id));

        $code = Artisan::call('mail:fetch-inbound');

        expect($code)->toBe(0)
            ->and(Artisan::output())->toContain('1 comment, 1 duplicate, 1 ignored, 1 ticket')
            ->and($this->mailbox->processed)->toBe(['INBOX/1', 'Junk Mail/2', 'INBOX/3', 'INBOX/4'])
            ->and($this->mailbox->failed)->toBe([])
            ->and($this->mailbox->closed)->toBeTrue()
            ->and($this->acme->run(fn () => InboundEmail::query()->count()))->toBe(3);
    });

    it('moves a message that throws to Failed and carries on', function (): void {
        // An address longer than the contacts column: creating the contact fails inside the transaction.
        $this->mailbox->add(inboundMessage(['From' => str_repeat('a', 300).'@wayne-foods.test', 'To' => 'support+acme@shp.example']));
        $this->mailbox->add(inboundEml('gmail-reply', $this->a['ticket']->id));

        expect(Artisan::call('mail:fetch-inbound'))->toBe(1)
            ->and(Artisan::output())->toContain('1 comment, 1 failed')
            ->and($this->mailbox->failed)->toBe(['INBOX/1'])
            ->and($this->mailbox->processed)->toBe(['INBOX/2'])
            ->and($this->acme->run(fn () => InboundEmail::query()->count()))->toBe(1);
    });

    it('reports an unreachable mailbox as a failed run', function (): void {
        $this->mailbox->unreachable = true;

        expect(Artisan::call('mail:fetch-inbound'))->toBe(1)
            ->and(Artisan::output())->toContain('unreachable')
            ->and($this->mailbox->closed)->toBeTrue();
    });

    it('limits a run to the batch size', function (): void {
        $this->mailbox->add(inboundEml('gmail-reply', $this->a['ticket']->id));
        $this->mailbox->add(inboundEml('plain-new-ticket'));

        Artisan::call('mail:fetch-inbound', ['--limit' => 1]);

        expect($this->mailbox->processed)->toBe(['INBOX/1']);
    });
});
