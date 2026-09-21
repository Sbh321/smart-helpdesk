<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Requests;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Support\Http\Bulk\BulkRequestRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `{ticket_ids, agent_id?, team_id?}` for a manual assignment of every ticket, or `{ticket_ids, auto: true}`
 * to run the assigner on each one.
 */
final class BulkAssignRequest extends FormRequest
{
    use BulkRequestRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->idRules(),
            'auto' => ['sometimes', 'boolean', 'prohibits:agent_id,team_id'],
            'agent_id' => ['required_without_all:team_id,auto', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! AgentProfile::query()->whereKey($value)->exists()) {
                    $fail('The Agent does not exist in this Workspace.');
                }
            }],
            'team_id' => ['required_without_all:agent_id,auto', 'nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! Team::query()->whereKey($value)->exists()) {
                    $fail('The Team does not exist in this Workspace.');
                }
            }],
        ];
    }
}
