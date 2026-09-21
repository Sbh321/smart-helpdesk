<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewDuplicatesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:20000']];
    }
}
