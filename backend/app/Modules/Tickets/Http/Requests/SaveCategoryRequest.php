<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class SaveCategoryRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $category = $this->route('category');

        return [
            'name' => [$required, 'string', 'max:80', function (string $attribute, mixed $value, Closure $fail) use ($category): void {
                $taken = is_string($value) && Category::query()
                    ->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($category instanceof Category, fn ($query) => $query->whereKeyNot($category->id))
                    ->exists();
                if ($taken) {
                    $fail('A category with this name already exists in this workspace.');
                }
            }],
            'default_team_id' => ['sometimes', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && (! is_string($value) || ! Team::query()->whereKey($value)->exists())) {
                    $fail('The selected default team is invalid.');
                }
            }],
            'skill_ids' => ['sometimes', 'array', 'max:50', 'distinct'],
            'skill_ids.*' => ['uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Skill::query()->whereKey($value)->exists()) {
                    $fail('The selected skill is invalid.');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:32767'],
        ];
    }
}
