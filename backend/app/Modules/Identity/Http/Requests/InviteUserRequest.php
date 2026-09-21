<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class InviteUserRequest extends FormRequest
{
    use RoleNamesRules;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', function (string $attribute, mixed $value, Closure $fail): void {
                // Scoped by the tenant global scope; the unique index is on lower(email).
                if (is_string($value) && User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($value))])->exists()) {
                    $fail('A user with this email address is already in the workspace.');
                }
            }],
            ...$this->roleRules('required'),
        ];
    }
}
