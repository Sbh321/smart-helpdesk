<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

use App\Modules\Contacts\Http\Resources\ContactResource;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Sla\Models\TicketSlaTimer;
use App\Modules\Tickets\Http\Resources\TicketCommentResource;
use App\Modules\Tickets\Http\Resources\TicketResource;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Builds the `data` member of webhook payloads from the REST resources of api_version v1, so
 * integrators learn one schema (docs/07-api/webhooks.md §Payload envelope). Resources are resolved
 * against an empty request: nothing in a payload depends on who triggered the event (for example
 * `allowed_transitions` is always empty).
 */
final class WebhookPayloads
{
    /**
     * @return array<string, mixed>|null the ticket resource with contact, organization, category and tags
     */
    public function ticket(string $ticketId): ?array
    {
        $ticket = Ticket::query()->with(['contact', 'organization', 'category', 'tags'])->find($ticketId);

        return $ticket === null ? null : $this->resolve(new TicketResource($ticket));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function comment(string $commentId): ?array
    {
        $comment = TicketComment::query()->with('mediaLinks.item')->find($commentId);

        return $comment === null ? null : $this->resolve(new TicketCommentResource($comment));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contact(string $contactId): ?array
    {
        $contact = Contact::query()->with(['organization', 'tags'])->find($contactId);

        return $contact === null ? null : $this->resolve(new ContactResource($contact));
    }

    /**
     * @return array{id: string, kind: string, due_at: string, breached_at: string|null}|null
     */
    public function timer(string $timerId): ?array
    {
        $timer = TicketSlaTimer::query()->find($timerId);

        return $timer === null ? null : [
            'id' => $timer->id,
            'kind' => $timer->kind->value,
            'due_at' => $timer->due_at->toIso8601ZuluString(),
            'breached_at' => $timer->breached_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Plain arrays all the way down, as they will be stored and sent.
     *
     * @return array<string, mixed>
     */
    private function resolve(JsonResource $resource): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) json_encode($resource->resolve(Request::create('/'))), true);

        return $data;
    }
}
