<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

/**
 * Separate PATCH schema so generated clients can send availability alone. `user_id` is
 * create-only: a profile never moves to another User.
 */
final class UpdateAgentRequest extends SaveAgentRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['user_id' => ['prohibited'], ...array_diff_key(parent::rules(), ['user_id' => true])];
    }
}
