<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Requests;

use App\Modules\Contacts\Models\Tag;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class TagRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40', $this->uniqueSlug()],
            'color' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[a-z]+(-[0-9]+)?$/'],
        ];
    }

    private function uniqueSlug(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Tag::query()->where('slug', Str::slug($value))->exists()) {
                $fail('This tag already exists.');
            }
        };
    }
}
