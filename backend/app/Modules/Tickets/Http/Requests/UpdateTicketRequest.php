<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Tickets\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateTicketRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'required', 'string', 'max:20000'],
            'category_id' => ['sometimes', 'required', 'uuid', $this->activeCategoryInWorkspace()],
            'impact' => ['sometimes', 'required', 'integer', 'between:1,4'],
            'urgency' => ['sometimes', 'required', 'integer', 'between:1,4'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
        ];
    }

    private function activeCategoryInWorkspace(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! Category::query()->whereKey($value)->where('is_active', true)->exists()) {
                $fail('The selected category does not exist.');
            }
        };
    }
}
