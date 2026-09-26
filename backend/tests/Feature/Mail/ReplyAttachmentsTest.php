<?php

declare(strict_types=1);

use App\Modules\Mail\Notifications\PublicReplyToContact;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';
require_once __DIR__.'/../Media/MediaTestSupport.php';

/*
 * The files of an agent's public reply travel with the mail to the requester (images and documents),
 * as far as they fit the size limit; the rest are named in the mail.
 */

beforeEach(function (): void {
    Notification::fake();
    useMediaTestDisk();
    $this->acme = createTenant('acme');
    actingAsRole($this->acme, 'agent');
    [$contact, $category] = ticketPrerequisites($this->acme);
    $this->ticket = Ticket::factory()->forContact($contact, $category)
        ->create(['status' => TicketStatus::InProgress, 'title' => 'Printer offline']);
});

afterEach(fn () => cleanMediaTestDisk());

/** A ready media item of the workspace with its object in storage. */
function storedFile(Tenant $tenant, string $name, string $mime, string $bytes): MediaItem
{
    $id = (string) Str::uuid7();
    $key = MediaKeys::original($id, pathinfo($name, PATHINFO_EXTENSION));
    putMediaObject($tenant, $key, $bytes);

    return mediaItemIn($tenant, 'ready', [
        'id' => $id, 'name' => $name, 'mime_type' => $mime, 'size_bytes' => strlen($bytes), 'storage_key' => $key,
    ]);
}

/** The mail of the reply, built as the queue worker builds it. */
function sentReply(): MailMessage
{
    $message = null;
    Notification::assertSentOnDemand(PublicReplyToContact::class, function (PublicReplyToContact $mail, array $channels, AnonymousNotifiable $to) use (&$message): bool {
        $message = $mail->toMail($to);

        return true;
    });

    return $message;
}

function replyWith(array $mediaIds): void
{
    test()->postJson('/v1/tickets/'.test()->ticket->id.'/comments', [
        'body' => 'Here is the screenshot and the manual.',
        'visibility' => 'public',
        'media_ids' => $mediaIds,
    ])->assertCreated();
}

it('attaches an image and a document to the reply mail', function (): void {
    $png = pngBytes();
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    $image = storedFile($this->acme, 'screenshot.png', 'image/png', $png);
    $manual = storedFile($this->acme, 'manual.pdf', 'application/pdf', $pdf);

    replyWith([$image->id, $manual->id]);

    $message = sentReply();
    $files = collect($message->rawAttachments)->keyBy('name');
    expect($files->keys()->all())->toBe(['screenshot.png', 'manual.pdf'])
        ->and($files['screenshot.png']['data'])->toBe($png)
        ->and($files['screenshot.png']['options']['mime'])->toBe('image/png')
        ->and($files['manual.pdf']['data'])->toBe($pdf)
        ->and($files['manual.pdf']['options']['mime'])->toBe('application/pdf');

    $html = view($message->view['html'], $message->viewData)->render();
    expect($html)->toContain('Attached: screenshot.png, manual.pdf')->not->toContain('Not attached');
});

it('names the files that do not fit the size limit instead of attaching them', function (): void {
    config(['helpdesk.mail.attachments_max_bytes' => 100]);
    $small = storedFile($this->acme, 'note.txt', 'text/plain', 'Small note.');
    $large = storedFile($this->acme, 'scan.pdf', 'application/pdf', str_repeat('x', 500));

    replyWith([$small->id, $large->id]);

    $message = sentReply();
    expect(collect($message->rawAttachments)->pluck('name')->all())->toBe(['note.txt']);
    $text = view($message->view['text'], $message->viewData)->render();
    expect($text)->toContain('Attached: note.txt')
        ->toContain('Not attached to this email (too large or no longer available): scan.pdf');
});

it('names a file whose object is gone, and still sends the reply', function (): void {
    $gone = mediaItemIn($this->acme, 'ready', ['name' => 'lost.png', 'mime_type' => 'image/png', 'size_bytes' => 10]);

    replyWith([$gone->id]);

    $message = sentReply();
    expect($message->rawAttachments)->toBe([]);
    $html = view($message->view['html'], $message->viewData)->render();
    expect($html)->toContain('lost.png');
});

it('sends a reply without files as before', function (): void {
    replyWith([]);

    $message = sentReply();
    expect($message->rawAttachments)->toBe([]);
    $html = view($message->view['html'], $message->viewData)->render();
    expect($html)->not->toContain('Attached:')->not->toContain('Not attached');
});
