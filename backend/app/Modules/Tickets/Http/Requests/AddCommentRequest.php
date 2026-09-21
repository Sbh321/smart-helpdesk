<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->filled('media_ids') || (bool) $this->user()?->can('media.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:20000'],
            'visibility' => ['required', Rule::in(['public', 'internal'])],
            'media_ids' => ['sometimes', 'array', 'max:10'],
            'media_ids.*' => ['uuid', 'distinct'],
            'author_type' => ['sometimes', Rule::in(['user', 'contact']), function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === 'contact' && $this->input('visibility') !== 'public') {
                    $fail('A Contact comment must be public.');
                }
            }],
        ];
    }
}
