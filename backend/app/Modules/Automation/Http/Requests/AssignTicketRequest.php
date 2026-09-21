<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `{ team_id?, agent_id? }`, at least one. Identifiers of another workspace fail validation
 * (422) because both models are tenant-scoped.
 */
final class AssignTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'agent_id' => ['required_without:team_id', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! AgentProfile::query()->whereKey($value)->exists()) {
                    $fail('The Agent does not exist in this Workspace.');
                }
            }],
            'team_id' => ['required_without:agent_id', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! Team::query()->whereKey($value)->exists()) {
                    $fail('The Team does not exist in this Workspace.');
                }
            }],
        ];
    }
}
