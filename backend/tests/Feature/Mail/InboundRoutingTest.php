<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Mail\Events\InboundEmailProcessed;
use App\Modules\Mail\Models\InboundEmail;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use App\Modules\Tickets\Models\TicketEvent;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/InboundEmailTestSupport.php';
require_once __DIR__.'/../Media/MediaTestSupport.php';

// Inbound email routing, idempotency and isolation (docs/04-domain/email.md §Routing, M3-19).

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

/** @return list<string> comment bodies of a ticket in its workspace, oldest first */
function commentBodies(Ticket $ticket): array
{
    return Tenant::query()->findOrFail($ticket->tenant_id)->run(
        fn (): array => TicketComment::query()->where('ticket_id', $ticket->id)->orderBy('created_at')->orderBy('id')->pluck('body')->all(),
    );
}

describe('rule 1: plus-address', function (): void {
    it('adds a Gmail reply as a public comment by the requester, quotes and signature cut', function (): void {
        $outcome = processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));
        $row = inboundRow($outcome);

        $comment = $this->acme->run(fn () => TicketComment::query()->findOrFail($row->comment_id));
        expect($outcome->state)->toBe('comment')
            ->and($row->tenant_id)->toBe($this->acme->id)
            ->and($row->route)->toBe('plus_address')
            ->and($row->ticket_id)->toBe($this->a['ticket']->id)
            ->and($row->contact_id)->toBe($this->a['asha']->id)
            ->and($row->reply_text)->toBe("Thanks, the printer works again.\n\nAsha")
            ->and($row->raw_key)->toBe("inbound/{$row->id}.eml")
            ->and(file_exists(mediaObjectPath($this->acme, (string) $row->raw_key)))->toBeTrue()
            ->and($comment->visibility)->toBe('public')
            ->and($comment->author_type)->toBe('contact')
            ->and($comment->author_id)->toBe($this->a['asha']->id)
            ->and($comment->body)->toBe("Thanks, the printer works again.\n\nAsha");

        $history = $this->acme->run(fn () => TicketEvent::query()->where('ticket_id', $this->a['ticket']->id)->where('type', 'comment_added')->sole());
        expect($history->actor_type)->toBe('system')->and($history->actor_id)->toBeNull();
    });

    it('accepts a colleague from the ticket\'s organisation and names them as the author', function (): void {
        $row = inboundRow(processInbound(inboundEml('outlook-reply', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('comment')
            ->and($row->contact_id)->toBe($this->a['bikram']->id)
            ->and($this->acme->run(fn () => TicketComment::query()->findOrFail($row->comment_id)->author_id))->toBe($this->a['bikram']->id);
    });

    it('rejects a sender who is neither the requester nor a contact of the organisation', function (string $from): void {
        $outcome = processInbound(inboundMessage(['From' => $from, 'To' => "ticket+{$this->a['ticket']->id}@shp.example"]));
        $row = inboundRow($outcome);

        expect($outcome->state)->toBe('rejected')
            ->and($row->reason)->toBe('sender_not_allowed')
            ->and($row->tenant_id)->toBe($this->acme->id)
            ->and($row->ticket_id)->toBe($this->a['ticket']->id)
            ->and(commentBodies($this->a['ticket']))->toBe([]);
    })->with([
        'a stranger' => ['Mallory <mallory@evil.test>'],
        'a contact without the organisation' => ['Zara Ali <zara.ali@wayne-foods.test>'],
    ]);

    it('rejects an archived requester', function (): void {
        $this->acme->run(fn () => $this->a['asha']->forceFill(['archived_at' => now()])->save());

        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('rejected')->and($row->reason)->toBe('sender_not_allowed');
    });

    it('rejects a forged plus-address for another workspace\'s ticket sent to this workspace\'s intake address', function (): void {
        // Globex's ticket id with Acme's intake address, from Acme's requester.
        $outcome = processInbound(inboundMessage([
            'To' => "support+acme@shp.example, ticket+{$this->g['ticket']->id}@shp.example",
        ], 'Please close this for me.'));
        $row = inboundRow($outcome);

        expect($outcome->state)->toBe('rejected')
            ->and($row->reason)->toBe('tenant_mismatch')
            ->and($row->tenant_id)->toBe($this->globex->id)
            ->and(commentBodies($this->g['ticket']))->toBe([])
            ->and($this->acme->run(fn () => InboundEmail::query()->count()))->toBe(0)
            ->and($this->acme->run(fn () => Ticket::query()->count()))->toBe(1);
    });

    it('never lets a sender of one workspace write on another workspace\'s ticket', function (): void {
        // Globex's ticket id alone, from Acme's requester: Globex has no contact with that address.
        $this->globex->run(fn () => $this->g['asha']->forceFill(['email' => 'asha@globex-customer.test'])->save());

        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->g['ticket']->id)));

        expect($row->state->value)->toBe('rejected')
            ->and($row->reason)->toBe('sender_not_allowed')
            ->and(commentBodies($this->g['ticket']))->toBe([])
            ->and(commentBodies($this->a['ticket']))->toBe([]);
    });

    it('falls through to the intake address when the plus-address names no ticket', function (): void {
        $outcome = processInbound(inboundMessage([
            'To' => 'ticket+01a0c741-0000-7000-8000-000000000000@shp.example, support+acme@shp.example',
            'Subject' => 'New problem',
        ]));

        expect($outcome->state)->toBe('ticket')->and(inboundRow($outcome)->route)->toBe('intake');
    });
});

describe('rule 2: thread headers', function (): void {
    it('finds the ticket from In-Reply-To when the plus-address is gone', function (): void {
        $this->acme->run(fn () => $this->a['asha']->forceFill(['email' => 'chen.wei@wayne-foods.test'])->save());

        $row = inboundRow(processInbound(inboundEml('apple-mail-reply', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('comment')
            ->and($row->route)->toBe('thread')
            ->and(commentBodies($this->a['ticket']))->toBe(['Thanks, I can print again. Café is open too.']);
    });

    it('prefers the thread over the intake address and reads References when In-Reply-To is foreign', function (): void {
        $ticket = $this->a['ticket']->id;
        $row = inboundRow(processInbound(inboundMessage([
            'To' => 'support+acme@shp.example',
            'In-Reply-To' => '<CAF+someone-else@mail.gmail.test>',
            'References' => "<ticket-{$ticket}.0@shp.example> <CAF+someone-else@mail.gmail.test>",
        ], 'Following up on this.')));

        expect($row->state->value)->toBe('comment')
            ->and($row->route)->toBe('thread')
            ->and(commentBodies($this->a['ticket']))->toBe(['Following up on this.']);
    });

    it('ignores Message-IDs on another domain', function (): void {
        $ticket = $this->a['ticket']->id;
        $outcome = processInbound(inboundMessage(['To' => 'someone@shp.example', 'In-Reply-To' => "<ticket-{$ticket}.1@evil.example>"]));

        expect($outcome->state)->toBe('unrouted')
            ->and($outcome->tenantId)->toBeNull()
            ->and(inboundRow($outcome)->reason)->toBe('no_route');
    });
});

describe('rule 3: intake address', function (): void {
    it('creates a ticket for a known contact with priority, SLA, duplicates and assignment run', function (): void {
        $this->acme->run(fn () => $this->a['ticket']->forceFill(['description' => 'The printer offline again since this morning.'])->save());

        $outcome = processInbound(inboundMessage([
            'From' => 'Asha Rai <asha@wayne-foods.test>',
            'To' => 'Acme Support <support+acme@shp.example>',
            'Subject' => 'Fwd: Printer offline again',
        ], "The printer offline again since this morning.\n\n-- \nAsha"));
        $row = inboundRow($outcome);

        $ticket = $this->acme->run(fn () => Ticket::query()->findOrFail($row->ticket_id));
        $timers = $this->acme->run(fn (): int => DB::table('ticket_sla_timers')->where('ticket_id', $ticket->id)->count());
        $history = $this->acme->run(fn () => TicketEvent::query()->where('ticket_id', $ticket->id)->pluck('type')->all());
        expect($outcome->state)->toBe('ticket')
            ->and($row->route)->toBe('intake')
            ->and($ticket->title)->toBe('Printer offline again')
            ->and($ticket->description)->toBe('The printer offline again since this morning.')
            ->and($ticket->contact_id)->toBe($this->a['asha']->id)
            ->and($ticket->organization_id)->toBe($this->a['organisation']->id)
            ->and($ticket->created_via)->toBe('email')
            ->and($ticket->impact)->toBe(1)
            ->and($ticket->urgency)->toBe(2)
            ->and($ticket->priority_score)->toBeGreaterThan(0)
            ->and($timers)->toBeGreaterThan(0)
            ->and($history)->toContain('created')
            // The existing "Printer offline" ticket shares most words: the duplicate strategy ran.
            ->and($this->acme->run(fn (): int => DB::table('ticket_duplicate_suggestions')->where('ticket_id', $ticket->id)->count()))->toBe(1);
    });

    it('turns an HTML-only message from an unknown sender into a contact and a ticket', function (): void {
        $row = inboundRow(processInbound(inboundEml('html-only')));

        $contact = $this->acme->run(fn () => Contact::query()->findOrFail($row->contact_id));
        $ticket = $this->acme->run(fn () => Ticket::query()->findOrFail($row->ticket_id));
        expect($row->state->value)->toBe('ticket')
            ->and($contact->email)->toBe('ravi@umbrella.test')
            ->and($contact->name)->toBe('Ravi K.')
            ->and($contact->organization_id)->toBeNull()
            ->and($ticket->title)->toBe('Invoice copy')
            ->and($ticket->description)->toBe("Could you send me a copy of invoice INV-204?\n\nThanks & regards,\nRavi");
    });

    it('links a new contact to the organisation of its domain unless that is switched off', function (bool $match, bool $linked): void {
        $this->acme->run(fn () => app(Settings::class)->update('email', ['match_organisation_domain' => $match]));

        $row = inboundRow(processInbound(inboundMessage(['From' => 'Dana <dana@Wayne-Foods.test>', 'To' => 'support+acme@shp.example', 'Subject' => 'Hello'])));

        $contact = $this->acme->run(fn () => Contact::query()->findOrFail($row->contact_id));
        expect($contact->organization_id)->toBe($linked ? $this->a['organisation']->id : null);
    })->with(['on' => [true, true], 'off' => [false, false]]);

    it('rejects an unknown sender when the workspace does not create contacts from email', function (): void {
        $this->acme->run(fn () => app(Settings::class)->update('email', ['create_contacts' => false]));

        $row = inboundRow(processInbound(inboundEml('html-only')));

        expect($row->state->value)->toBe('rejected')
            ->and($row->reason)->toBe('unknown_sender')
            ->and($this->acme->run(fn () => Contact::query()->where('email', 'ravi@umbrella.test')->exists()))->toBeFalse();
    });

    it('rejects an archived contact and a workspace without an active category', function (): void {
        $this->acme->run(fn () => $this->a['outsider']->forceFill(['archived_at' => now()])->save());
        $archived = inboundRow(processInbound(inboundEml('plain-new-ticket')));

        $this->acme->run(fn () => $this->a['category']->forceFill(['is_active' => false])->save());
        $noCategory = inboundRow(processInbound(inboundEml('html-only')));

        expect($archived->reason)->toBe('sender_archived')
            ->and($noCategory->reason)->toBe('no_category');
    });

    it('leaves mail to a suspended or unknown workspace unrouted, on the platform', function (): void {
        $this->globex->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        $suspended = processInbound(inboundMessage(['To' => 'support+globex@shp.example']));
        $unknown = processInbound(inboundMessage(['To' => 'support+nobody@shp.example']));

        expect($suspended->state)->toBe('unrouted')
            ->and($suspended->tenantId)->toBeNull()
            ->and(inboundRow($suspended)->reason)->toBe('unknown_target')
            ->and($unknown->state)->toBe('unrouted');
    });
});

describe('reopen window', function (): void {
    it('reopens a resolved ticket inside the window, then adds the comment', function (): void {
        $this->acme->run(fn () => $this->a['ticket']->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()->subDays(3)])->save());

        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));

        $ticket = findInAnyTenant(Ticket::class, $this->a['ticket']->id);
        $reopened = $this->acme->run(fn () => TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'reopened')->sole());
        expect($ticket->status)->toBe(TicketStatus::InProgress)
            ->and($ticket->reopen_count)->toBe(1)
            ->and($ticket->resolved_at)->toBeNull()
            ->and($reopened->actor_type)->toBe('system')
            ->and($reopened->note)->toBe('Requester replied by email')
            ->and(commentBodies($ticket))->toBe(["Thanks, the printer works again.\n\nAsha"]);
    });

    it('opens a follow-up ticket when the window is over', function (): void {
        $this->acme->run(fn () => $this->a['ticket']->forceFill(['status' => TicketStatus::Closed, 'resolved_at' => now()->subDays(30), 'closed_at' => now()->subDays(20)])->save());

        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)));

        $followUp = $this->acme->run(fn () => Ticket::query()->findOrFail($row->ticket_id));
        expect($row->state->value)->toBe('ticket')
            ->and($row->reason)->toBe('reopen_window_expired')
            ->and($row->route)->toBe('plus_address')
            ->and($followUp->id)->not->toBe($this->a['ticket']->id)
            ->and($followUp->contact_id)->toBe($this->a['asha']->id)
            ->and($followUp->description)->toStartWith("Follow-up to #{$this->a['ticket']->number}")
            ->and(findInAnyTenant(Ticket::class, $this->a['ticket']->id)->status)->toBe(TicketStatus::Closed);
    });

    it('opens a follow-up for a ticket closed as a duplicate', function (): void {
        $original = $this->acme->run(fn () => Ticket::factory()->forContact($this->a['asha'], $this->a['category'])->create());
        $this->acme->run(fn () => $this->a['ticket']->forceFill(['status' => TicketStatus::Closed, 'resolved_at' => now(), 'closed_at' => now(), 'duplicate_of_id' => $original->id])->save());

        expect(inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)))->reason)->toBe('closed_as_duplicate');
    });

    it('resumes a pending ticket without reopening it', function (): void {
        $this->acme->run(fn () => $this->a['ticket']->forceFill(['status' => TicketStatus::Pending, 'pending_since' => now()])->save());

        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));

        expect(findInAnyTenant(Ticket::class, $this->a['ticket']->id)->status)->toBe(TicketStatus::InProgress);
    });
});

describe('auto-replies, bounces and empty replies', function (): void {
    it('logs an auto-reply on the ticket\'s workspace without a comment', function (): void {
        $outcome = processInbound(inboundEml('auto-reply', $this->a['ticket']->id));
        $row = inboundRow($outcome);

        expect($outcome->state)->toBe('ignored')
            ->and($row->reason)->toBe('auto_reply')
            ->and($row->tenant_id)->toBe($this->acme->id)
            ->and(commentBodies($this->a['ticket']))->toBe([]);
    });

    it('logs a bounce to the intake address without creating a ticket or a contact', function (): void {
        $row = inboundRow(processInbound(inboundEml('bounce')));

        expect($row->state->value)->toBe('ignored')
            ->and($row->reason)->toBe('bounce')
            ->and($this->acme->run(fn () => Ticket::query()->count()))->toBe(1)
            ->and($this->acme->run(fn () => Contact::query()->where('email', 'like', 'mailer-daemon%')->exists()))->toBeFalse();
    });

    it('ignores a reply with nothing new in it', function (): void {
        $row = inboundRow(processInbound(inboundMessage(['To' => "ticket+{$this->a['ticket']->id}@shp.example"], "> just the quote\n")));

        expect($row->state->value)->toBe('ignored')->and($row->reason)->toBe('empty_reply');
    });

    it('refuses a message above the size limit without storing it', function (): void {
        config(['helpdesk.mail.inbound.max_message_bytes' => 100]);

        $row = inboundRow(processInbound(inboundEml('gmail-reply', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('rejected')
            ->and($row->reason)->toBe('too_large')
            ->and($row->raw_key)->toBeNull()
            ->and($row->text_body)->toBeNull()
            ->and($row->raw_size)->toBeGreaterThan(100)
            ->and(commentBodies($this->a['ticket']))->toBe([]);
    });
});

describe('attachments', function (): void {
    it('stores allowed files in the Email folder, links them to the comment and lists every file', function (): void {
        // The attachments fixture comes from Zara; make her the requester.
        $this->acme->run(fn () => $this->a['outsider']->delete());
        $this->acme->run(fn () => $this->a['asha']->forceFill(['email' => 'zara.ali@wayne-foods.test'])->save());

        $row = inboundRow(processInbound(inboundEml('with-attachments', $this->a['ticket']->id)));

        $items = $this->acme->run(fn () => MediaItem::query()->with('folder')->orderBy('name')->get());
        $links = $this->acme->run(fn () => Mediable::query()->where('mediable_type', 'ticket_comment')->where('mediable_id', $row->comment_id)->count());
        expect($row->state->value)->toBe('comment')
            ->and(array_map(fn (array $file): array => [$file['name'], $file['skipped']], $row->attachments))->toBe([
                ['screen.png', null],
                ['printer-log.pdf', null],
                ['fix.exe', 'type_not_allowed'],
                ['logo.png', 'inline'],
            ])
            ->and($items->pluck('name')->all())->toBe(['printer-log.pdf', 'screen.png'])
            ->and($items->pluck('source')->unique()->all())->toBe(['email'])
            ->and($items->pluck('state')->unique()->all())->toBe(['ready'])
            ->and($items->first()->folder->system_key)->toBe('email')
            ->and($items->first()->uploaded_by_user_id)->toBeNull()
            ->and($links)->toBe(2)
            ->and(usedBytesOf($this->acme))->toBe((int) $items->sum('size_bytes'));
    });

    it('lists but does not store attachments when the workspace is out of quota', function (): void {
        $this->acme->forceFill(['storage_quota_bytes' => 10])->save();
        // The attachments fixture comes from Zara; make her the requester.
        $this->acme->run(fn () => $this->a['outsider']->delete());
        $this->acme->run(fn () => $this->a['asha']->forceFill(['email' => 'zara.ali@wayne-foods.test'])->save());

        $row = inboundRow(processInbound(inboundEml('with-attachments', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('comment')
            ->and(array_column($row->attachments, 'skipped'))->toBe(['quota_exceeded', 'quota_exceeded', 'type_not_allowed', 'inline'])
            ->and($this->acme->run(fn () => MediaItem::query()->where('state', 'ready')->count()))->toBe(0);
    });

    it('does not store attachments of a rejected message', function (): void {
        $row = inboundRow(processInbound(inboundEml('with-attachments', $this->a['ticket']->id)));

        expect($row->state->value)->toBe('rejected')
            ->and(array_column($row->attachments, 'skipped'))->toBe(['not_stored', 'not_stored', 'not_stored', 'not_stored']);
    });
});

describe('idempotency', function (): void {
    it('creates one comment when the same message is processed twice', function (): void {
        $raw = inboundEml('gmail-reply', $this->a['ticket']->id);
        Event::fake([InboundEmailProcessed::class]);

        $first = processInbound($raw);
        $second = processInbound($raw);

        expect($first->state)->toBe('comment')
            ->and($second->state)->toBe('duplicate')
            ->and(commentBodies($this->a['ticket']))->toHaveCount(1)
            ->and($this->acme->run(fn () => InboundEmail::query()->count()))->toBe(1);
        Event::assertDispatchedTimes(InboundEmailProcessed::class, 1);
    });

    it('creates one ticket when the same intake message is processed twice', function (): void {
        $raw = inboundEml('plain-new-ticket');

        processInbound($raw);
        processInbound($raw);

        expect($this->acme->run(fn () => Ticket::query()->count()))->toBe(2);
    });

    it('logs an unrouted message once on the platform and names a message without a Message-ID by its hash', function (): void {
        $raw = "From: a@b.test\r\nTo: nobody@else.test\r\nSubject: hi\r\n\r\nbody\r\n";

        $first = processInbound($raw);
        $second = processInbound($raw);

        expect($first->state)->toBe('unrouted')
            ->and($second->state)->toBe('duplicate')
            ->and(inboundRow($first)->message_id)->toBe('sha256.'.hash('sha256', $raw).'@missing-message-id')
            ->and(InboundEmail::query()->count())->toBe(1);
    });

    it('keeps the same Message-ID apart per workspace', function (): void {
        $id = '<shared-id@wayne-foods.test>';
        processInbound(inboundMessage(['Message-ID' => $id, 'To' => 'support+acme@shp.example']));
        processInbound(inboundMessage(['Message-ID' => $id, 'To' => 'support+globex@shp.example']));

        expect($this->acme->run(fn () => InboundEmail::query()->count()))->toBe(1)
            ->and($this->globex->run(fn () => InboundEmail::query()->count()))->toBe(1);
    });
});

describe('isolation', function (): void {
    it('keeps platform rows out of every workspace and workspace rows out of the platform', function (): void {
        processInbound(inboundMessage(['To' => 'nobody@shp.example']));
        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));

        expect(DB::table('inbound_emails')->pluck('state')->all())->toBe(['unrouted'])
            ->and($this->acme->run(fn () => DB::table('inbound_emails')->pluck('state')->all()))->toBe(['comment'])
            ->and($this->globex->run(fn () => DB::table('inbound_emails')->count()))->toBe(0);
    });

    it('sends no mail back to the sender of an inbound message', function (): void {
        processInbound(inboundEml('gmail-reply', $this->a['ticket']->id));
        processInbound(inboundEml('html-only'));

        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        expect(iterator_to_array($transport->messages()))->toBe([]);
    });
});

function usedBytesOf(Tenant $tenant): int
{
    return (int) DB::table('tenant_counters')->where('tenant_id', $tenant->getKey())->value('storage_used_bytes');
}
