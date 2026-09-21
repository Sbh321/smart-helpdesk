<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MarkDuplicateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['candidate_ticket_id' => ['required', 'uuid']];
    }
}
