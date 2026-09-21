<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Models\User;
use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $agent = $this->route('agent');

        return $actor?->can('agents.manage') === true
            || ($agent instanceof AgentProfile
                && $agent->user_id === $actor?->id
                && array_keys($this->all()) === ['availability']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            // Create-only: UpdateAgentRequest prohibits it on PATCH.
            'user_id' => ['required', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! User::query()->whereKey($value)->where('is_active', true)->exists()) {
                    $fail('The selected user is invalid.');

                    return;
                }
                if (AgentProfile::query()->where('user_id', $value)->exists()) {
                    $fail('The selected user already has an agent profile.');
                }
            }],
            'capacity' => [$required, 'integer', 'min:1', 'max:100'],
            'availability' => [$required, Rule::enum(AgentAvailability::class)],
            'skills' => ['sometimes', 'array', 'max:50'],
            'skills.*.skill_id' => ['required', 'uuid', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Skill::query()->whereKey($value)->exists()) {
                    $fail('The selected skill is invalid.');
                }
            }],
            'skills.*.level' => ['required', 'integer', 'between:1,5'],
            'team_ids' => ['sometimes', 'array', 'max:50', 'distinct'],
            'team_ids.*' => ['uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Team::query()->whereKey($value)->exists()) {
                    $fail('The selected team is invalid.');
                }
            }],
        ];
    }
}
