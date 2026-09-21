<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Queries\TicketTransitionRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransitionTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $ticket = $this->route('ticket');
        $target = TicketStatus::tryFrom($this->string('status')->toString());

        if ($actor === null || ! $actor->can('tickets.update') || ! $ticket instanceof Ticket || $target === null) {
            return $actor !== null && $actor->can('tickets.update');
        }

        return match (true) {
            $target === TicketStatus::Resolved => $actor->can('tickets.resolve'),
            $target === TicketStatus::Closed => $actor->can('tickets.close'),
            app(TicketTransitionRules::class)->isReopen($ticket->status, $target) => $actor->can('tickets.reopen'),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
