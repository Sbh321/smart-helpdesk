<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Modules\Agents\Models\AgentProfile;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class ReplaceTeamMembersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_ids' => ['required', 'array', 'max:100', 'distinct'],
            'agent_ids.*' => ['uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! AgentProfile::query()->whereKey($value)->exists()) {
                    $fail('The selected agent is invalid.');
                }
            }],
        ];
    }
}
