<?php

declare(strict_types=1);

namespace App\Modules\Mail\Actions;

use App\Modules\Automation\Domain\Text\HtmlToText;
use App\Modules\Automation\Domain\Text\ReplyParser;
use App\Modules\Contacts\Actions\FindOrCreateContact;
use App\Modules\Mail\Domain\AutomatedMailDetector;
use App\Modules\Mail\Domain\ParsedAttachment;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Mail\Domain\SubjectLine;
use App\Modules\Mail\Enums\InboundState;
use App\Modules\Mail\Events\InboundEmailProcessed;
use App\Modules\Mail\Models\InboundEmail;
use App\Modules\Mail\Support\InboundOutcome;
use App\Modules\Mail\Support\InboundRoute;
use App\Modules\Mail\Support\InboundRouter;
use App\Modules\Mail\Support\MimeParser;
use App\Modules\Media\Actions\AttachMedia;
use App\Modules\Media\Actions\StoreEmailAttachment;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Actions\AddComment;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Actions\ReopenTicketForRequester;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles one raw inbound message end to end (docs/04-domain/email.md §Inbound pipeline): parse,
 * route, then inside the routed workspace (or centrally for a message that names none) either add a
 * public comment as the requester, create a ticket, or log why nothing happened. Idempotent on the
 * Message-ID per workspace: a second delivery of the same message logs nothing and changes nothing.
 *
 * New tickets go through `CreateTicket`, so priority, SLA timers, duplicate suggestions and automatic
 * assignment run through their strategy contracts exactly as for a ticket created in the API.
 */
final readonly class ProcessInboundEmail
{
    private const int MAX_BODY = 200_000;

    private const int MAX_COMMENT = 20_000;

    public function __construct(
        private MimeParser $parser,
        private InboundRouter $router,
        private AutomatedMailDetector $detector,
        private ReplyParser $replies,
        private HtmlToText $htmlToText,
        private CreateTicket $createTicket,
        private AddComment $addComment,
        private ReopenTicketForRequester $reopen,
        private FindOrCreateContact $contacts,
        private StoreEmailAttachment $storeAttachment,
        private AttachMedia $attachMedia,
        private MediaStorage $storage,
        private Settings $settings,
        private Clock $clock,
    ) {}

    public function __invoke(string $raw): InboundOutcome
    {
        $email = $this->parser->parse($raw);
        $messageId = $email->messageId ?? 'sha256.'.hash('sha256', $raw).'@missing-message-id';
        $route = $this->router->route($email);
        $work = fn (): InboundOutcome => $this->handle($raw, $email, $messageId, $route);

        return $route->tenant === null ? $work() : $route->tenant->run($work);
    }

    private function handle(string $raw, ParsedEmail $email, string $messageId, InboundRoute $route): InboundOutcome
    {
        $tenantId = $route->tenant?->id;
        if ($this->alreadyLogged($tenantId, $messageId)) {
            return new InboundOutcome(InboundOutcome::DUPLICATE, tenantId: $tenantId);
        }

        $text = $email->text ?? ($email->html !== null ? $this->htmlToText->convert($email->html) : '');
        $reply = $this->replies->parse($text);
        $tooLarge = strlen($raw) > (int) config('helpdesk.mail.inbound.max_message_bytes');
        $automated = $this->detector->detect($email);

        try {
            $row = DB::transaction(function () use ($email, $messageId, $route, $text, $reply, $tooLarge, $automated): InboundEmail {
                [$state, $reason, $ticketId, $commentId, $contactId] = match (true) {
                    $tooLarge => [$route->tenant === null ? InboundState::Unrouted : InboundState::Rejected, 'too_large', $route->ticketId, null, null],
                    $automated !== null => [InboundState::Ignored, $automated, $route->ticketId, null, null],
                    $route->kind === InboundRoute::UNROUTED => [InboundState::Unrouted, $route->reason, null, null, null],
                    $route->kind === InboundRoute::REJECTED => [InboundState::Rejected, $route->reason, $route->ticketId, null, null],
                    $route->kind === InboundRoute::TICKET => $this->reply($email, $route, $text, $reply),
                    default => $this->newTicket($email, $route, $text, $reply),
                };

                return $this->log($email, $messageId, $route, $state, $reason, $ticketId, $commentId, $contactId, $tooLarge ? null : $text, $reply);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'inbound_emails_message_key')) {
                // A concurrent run logged the same message first; everything above rolled back.
                return new InboundOutcome(InboundOutcome::DUPLICATE, tenantId: $tenantId);
            }

            throw $exception;
        }

        $this->keepRaw($row, $raw, $tooLarge);
        $this->storeAttachments($row, $tooLarge ? [] : $email->attachments);

        Log::info('mail.inbound.processed', [
            'inbound_email_id' => $row->id,
            'state' => $row->state->value,
            'reason' => $row->reason,
            'route' => $row->route,
            'ticket_id' => $row->ticket_id,
        ]);

        event(new InboundEmailProcessed($tenantId, $row->id, $row->state->value, $row->reason, $row->ticket_id, $row->comment_id));

        return new InboundOutcome($row->state->value, $row->id, $tenantId, $row->reason);
    }

    /**
     * A reply on a known ticket: reopened when it is finished and still inside the reopen window, then
     * the new text as a public comment by the contact. A ticket that can no longer be reopened (window
     * over, or closed as a duplicate) gets a follow-up ticket instead.
     *
     * @return array{InboundState, string|null, string|null, string|null, string|null}
     */
    private function reply(ParsedEmail $email, InboundRoute $route, string $text, string $reply): array
    {
        $ticket = Ticket::query()->findOrFail($route->ticketId);

        if (! $this->reopen->accepts($ticket)) {
            return $this->newTicket($email, $route, $text, $reply, $ticket, (string) $route->contactId);
        }

        $body = $reply !== '' ? $reply : ($this->storableAttachments($email) !== [] ? 'Sent attachments by email.' : '');
        if ($body === '') {
            return [InboundState::Ignored, 'empty_reply', $ticket->id, null, $route->contactId];
        }

        ($this->reopen)($ticket);
        $comment = ($this->addComment)($ticket, mb_substr($body, 0, self::MAX_COMMENT), 'public', 'contact', null, [], $route->contactId);

        return [InboundState::Comment, null, $ticket->id, $comment->id, $route->contactId];
    }

    /**
     * A new ticket from the sender, who is found or (when the workspace allows it) created as a
     * contact. `$previous` is the finished ticket a follow-up replaces.
     *
     * @return array{InboundState, string|null, string|null, string|null, string|null}
     */
    private function newTicket(ParsedEmail $email, InboundRoute $route, string $text, string $reply, ?Ticket $previous = null, ?string $contactId = null): array
    {
        $reason = $previous === null ? null : ($previous->duplicate_of_id !== null ? 'closed_as_duplicate' : 'reopen_window_expired');

        if ($contactId === null) {
            $sender = $email->from;
            $found = $sender === null ? ['contact' => null] : ($this->contacts)(
                $sender->address,
                $sender->name,
                (bool) $this->settings->get('email.create_contacts', true),
                (bool) $this->settings->get('email.match_organisation_domain', true),
            );
            $contact = $found['contact'];

            if ($contact === null) {
                return [InboundState::Rejected, 'unknown_sender', null, null, null];
            }
            if ($contact->isArchived()) {
                return [InboundState::Rejected, 'sender_archived', null, null, $contact->id];
            }
            $contactId = $contact->id;
        }

        $category = Category::query()->where('is_active', true)->orderByRaw("CASE WHEN lower(name) = 'general' THEN 0 ELSE 1 END")->orderBy('sort_order')->orderBy('id')->first();
        if ($category === null) {
            return [InboundState::Rejected, 'no_category', $previous?->id, null, $contactId];
        }

        $description = $reply !== '' ? $reply : trim($text);
        if ($previous !== null) {
            $description = "Follow-up to #{$previous->number} (the ticket could not be reopened).\n\n".$description;
        }

        $ticket = ($this->createTicket)([
            'title' => SubjectLine::toTitle($email->subject),
            'description' => mb_substr($description === '' ? '(The email had no text.)' : $description, 0, self::MAX_COMMENT),
            'contact_id' => $contactId,
            'category_id' => $category->id,
            'impact' => (int) config('helpdesk.mail.inbound.default_impact', 1),
            'urgency' => (int) config('helpdesk.mail.inbound.default_urgency', 2),
        ], null, 'email');

        return [InboundState::Ticket, $reason, $ticket->id, null, $contactId];
    }

    private function log(ParsedEmail $email, string $messageId, InboundRoute $route, InboundState $state, ?string $reason, ?string $ticketId, ?string $commentId, ?string $contactId, ?string $text, string $reply): InboundEmail
    {
        $now = $this->clock->now();
        $row = new InboundEmail;
        $row->id = (string) Str::uuid7();
        $row->forceFill([
            'tenant_id' => $route->tenant?->id,
            'message_id' => mb_substr($messageId, 0, 998),
            'from_address' => $email->from === null ? null : mb_substr($email->from->address, 0, 320),
            'from_name' => $email->from?->name === null ? null : mb_substr($this->utf8($email->from->name), 0, 200),
            'to_addresses' => array_map(fn ($address): string => $address->address, $email->to),
            'cc_addresses' => array_map(fn ($address): string => $address->address, $email->cc),
            'subject' => mb_substr($this->utf8($email->subject), 0, 998),
            'headers' => array_map(fn (array $values): array => array_map($this->utf8(...), $values), $email->headers),
            'text_body' => $text === null ? null : mb_substr($text, 0, self::MAX_BODY),
            'html_body' => $text === null || $email->html === null ? null : mb_substr($this->utf8($email->html), 0, self::MAX_BODY),
            'reply_text' => $text === null ? null : mb_substr($reply, 0, self::MAX_BODY),
            'state' => $state,
            'route' => $route->route,
            'reason' => $reason,
            // Platform rows never point into a workspace.
            'ticket_id' => $route->tenant === null ? null : $ticketId,
            'comment_id' => $commentId,
            'contact_id' => $route->tenant === null ? null : $contactId,
            'sent_at' => $email->sentAt,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row->save();

        return $row;
    }

    private function alreadyLogged(?string $tenantId, string $messageId): bool
    {
        return InboundEmail::query()
            ->when($tenantId === null, fn ($query) => $query->whereNull('tenant_id'), fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('message_id', mb_substr($messageId, 0, 998))
            ->exists();
    }

    /** Keeps the original message for audit next to the workspace's files (`inbound/<id>.eml`). */
    private function keepRaw(InboundEmail $row, string $raw, bool $tooLarge): void
    {
        if ($tooLarge) {
            $row->forceFill(['raw_size' => strlen($raw)])->save();

            return;
        }

        // MVP-SHORTCUT: the original is kept without counting against the quota and is never pruned; V1: V1-ML-09.
        $key = "inbound/{$row->id}.eml";
        try {
            $stored = $this->storage->put($key, $raw, 'message/rfc822');
        } catch (Throwable $exception) {
            report($exception);
            $stored = false;
        }

        $row->forceFill(['raw_key' => $stored ? $key : null, 'raw_size' => strlen($raw)])->save();
    }

    /**
     * Stores the attachments of a message that became a comment or a ticket as Media items in the
     * `Email` folder and links them; every file is listed on the log row with its outcome. Inline
     * parts (signature logos, embedded images) are listed but not stored.
     *
     * @param  list<ParsedAttachment>  $attachments
     */
    private function storeAttachments(InboundEmail $row, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }

        $subject = match ($row->state) {
            InboundState::Comment => ['ticket_comment', $row->comment_id],
            InboundState::Ticket => ['ticket', $row->ticket_id],
            default => null,
        };
        $listed = [];

        foreach ($attachments as $attachment) {
            $entry = ['name' => mb_substr($attachment->filename, 0, 255), 'size' => $attachment->size(), 'media_id' => null, 'skipped' => null];

            if ($subject === null || $subject[1] === null) {
                $entry['skipped'] = 'not_stored';
            } elseif ($attachment->inline) {
                $entry['skipped'] = 'inline';
            } else {
                $stored = ($this->storeAttachment)($attachment->filename, $attachment->contents);
                $entry['skipped'] = $stored['reason'];

                if ($stored['item'] !== null) {
                    try {
                        ($this->attachMedia)($stored['item'], $subject[0], $subject[1]);
                        $entry['media_id'] = $stored['item']->id;
                    } catch (ValidationException) {
                        $entry['skipped'] = 'limit';
                    }
                }
            }

            $listed[] = $entry;
        }

        $row->forceFill(['attachments' => $listed])->save();
    }

    /** @return list<ParsedAttachment> */
    private function storableAttachments(ParsedEmail $email): array
    {
        return array_values(array_filter($email->attachments, fn (ParsedAttachment $attachment): bool => ! $attachment->inline));
    }

    private function utf8(string $value): string
    {
        $value = mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return str_replace("\0", '', $value);
    }
}
