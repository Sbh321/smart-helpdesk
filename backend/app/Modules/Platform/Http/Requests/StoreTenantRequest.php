<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Tenancy\Rules\WorkspaceSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreTenantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', new WorkspaceSlug, Rule::unique('tenants', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email:filter', 'max:254'],
            'owner_name' => ['sometimes', 'string', 'max:120'],
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
            'plan_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('plans', 'id')->where('is_active', true)],
            'periods' => ['sometimes', 'integer', 'between:1,36'],
        ];
    }
}
