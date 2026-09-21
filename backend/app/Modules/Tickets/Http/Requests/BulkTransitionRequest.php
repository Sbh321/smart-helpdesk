<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Support\Http\Bulk\BulkRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `{ticket_ids, status, comment?}`: the same transition for up to 100 tickets. */
final class BulkTransitionRequest extends FormRequest
{
    use BulkRequestRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->idRules(),
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
