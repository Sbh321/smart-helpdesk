<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Role;
use Closure;

/**
 * `roles`: at least one role name of this workspace (a global default or a custom role).
 */
trait RoleNamesRules
{
    /** @return array<string, mixed> */
    protected function roleRules(string $required): array
    {
        return [
            'roles' => [$required, 'array', 'min:1', 'max:10'],
            'roles.*' => ['distinct', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Role::inWorkspace()->where('name', $value)->exists()) {
                    $fail('This role does not exist in the workspace.');
                }
            }],
        ];
    }
}
