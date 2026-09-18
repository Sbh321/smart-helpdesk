<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Support\PermissionCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [
                $required, 'string', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/',
                // Unique among this workspace's own roles and the global defaults it inherits.
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where(
                        fn ($inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', tenant()?->getTenantKey()),
                    ))
                    ->ignore($this->route('role')?->getKey()),
            ],
            'permissions' => [$required, 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalogue::all())],
        ];
    }
}
