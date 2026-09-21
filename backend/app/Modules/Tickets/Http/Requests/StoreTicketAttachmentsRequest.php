<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTicketAttachmentsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'media_ids' => ['required', 'array', 'min:1', 'max:50'],
            'media_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
