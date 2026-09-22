<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Mail\Actions\ProcessInboundEmail;
use App\Modules\Mail\Contracts\InboundMailbox;
use App\Modules\Mail\Models\InboundEmail;
use App\Modules\Mail\Support\FetchedMessage;
use App\Modules\Mail\Support\InboundOutcome;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;

/*
 * Inbound email feature tests (docs/04-domain/email.md §Tests). Fixtures live in tests/Fixtures/inbound
 * with `{{TICKET}}` where the ticket id goes; the mail domain is `shp.example`.
 */

/**
 * A workspace with the Wayne Foods organisation (domain wayne-foods.test), its requester Asha, her
 * colleague Bikram, an outsider, a category and one in-progress ticket from Asha.
 *
 * @return array{ticket: Ticket, asha: Contact, bikram: Contact, outsider: Contact, organisation: Organization, category: Category}
 */
function inboundWorkspace(Tenant $tenant): array
{
    return $tenant->run(function () use ($tenant): array {
        $organisation = Organization::factory()->forTenant($tenant)->create(['name' => 'Wayne Foods', 'domain' => 'wayne-foods.test']);
        $asha = Contact::factory()->forTenant($tenant)->create(['name' => 'Asha Rai', 'email' => 'asha@wayne-foods.test', 'organization_id' => $organisation->id]);
        $bikram = Contact::factory()->forTenant($tenant)->create(['name' => 'Bikram Thapa', 'email' => 'bikram@wayne-foods.test', 'organization_id' => $organisation->id]);
        $outsider = Contact::factory()->forTenant($tenant)->create(['name' => 'Zara Ali', 'email' => 'zara.ali@wayne-foods.test', 'organization_id' => null]);
        $category = Category::factory()->forTenant($tenant)->create(['name' => 'General']);
        $ticket = Ticket::factory()->forContact($asha, $category)->create(['status' => TicketStatus::InProgress, 'title' => 'Printer offline']);

        return compact('ticket', 'asha', 'bikram', 'outsider', 'organisation', 'category');
    });
}

function inboundEml(string $name, ?string $ticketId = null): string
{
    return str_replace('{{TICKET}}', (string) $ticketId, (string) file_get_contents(__DIR__."/../../Fixtures/inbound/{$name}.eml"));
}

/**
 * A minimal message: headers as given (From, To, Subject, Message-ID default), then the body.
 *
 * @param  array<string, string>  $headers
 */
function inboundMessage(array $headers, string $body = "Hello from the requester.\n"): string
{
    $headers += [
        'From' => 'Asha Rai <asha@wayne-foods.test>',
        'Subject' => 'Re: Printer offline',
        'Message-ID' => '<'.bin2hex(random_bytes(8)).'@wayne-foods.test>',
        'Date' => 'Tue, 22 Sep 2026 09:00:00 +0000',
        'Content-Type' => 'text/plain; charset=utf-8',
    ];
    $lines = array_map(fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers);

    return implode("\r\n", $lines)."\r\n\r\n".str_replace("\n", "\r\n", $body);
}

function processInbound(string $raw): InboundOutcome
{
    // Every message is handled from the central context, as the scheduler runs it.
    tenancy()->end();

    return app(ProcessInboundEmail::class)($raw);
}

/** The log row in whichever context holds it (a workspace, or the platform for a null tenant). */
function inboundRow(InboundOutcome $outcome): InboundEmail
{
    $find = fn (): InboundEmail => InboundEmail::query()->findOrFail($outcome->inboundEmailId);

    return $outcome->tenantId === null ? $find() : Tenant::query()->findOrFail($outcome->tenantId)->run($find);
}

/** An in-memory mailbox for mail:fetch-inbound (the production one speaks IMAP). */
final class FakeInboundMailbox implements InboundMailbox
{
    /** @var list<FetchedMessage> */
    public array $waiting = [];

    /** @var list<string> */
    public array $processed = [];

    /** @var list<string> */
    public array $failed = [];

    public bool $closed = false;

    public bool $unreachable = false;

    public function add(string $raw, string $folder = 'INBOX'): void
    {
        $this->waiting[] = new FetchedMessage($folder, (string) (count($this->waiting) + 1), $raw);
    }

    public function fetch(int $limit): iterable
    {
        if ($this->unreachable) {
            throw new RuntimeException('connection refused');
        }

        foreach (array_slice($this->waiting, 0, $limit) as $message) {
            yield $message;
        }
    }

    public function markProcessed(FetchedMessage $message): void
    {
        $this->processed[] = $message->folder.'/'.$message->uid;
    }

    public function markFailed(FetchedMessage $message): void
    {
        $this->failed[] = $message->folder.'/'.$message->uid;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
