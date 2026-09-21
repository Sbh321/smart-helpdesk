<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Modules\Agents\Models\Skill;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class SaveSkillRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $skill = $this->route('skill');

        return [
            'name' => [$required, 'string', 'max:60', $this->unique('name', $skill)],
            'slug' => [$required, 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $this->unique('slug', $skill)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    private function unique(string $column, mixed $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $ignore): void {
            $taken = is_string($value) && Skill::query()
                ->whereRaw("lower({$column}) = lower(?)", [$value])
                ->when($ignore instanceof Skill, fn ($query) => $query->whereKeyNot($ignore->id))
                ->exists();

            if ($taken) {
                $fail("A skill with this {$column} already exists in this workspace.");
            }
        };
    }
}
