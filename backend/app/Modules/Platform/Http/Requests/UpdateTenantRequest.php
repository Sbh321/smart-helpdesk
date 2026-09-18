<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateTenantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'owner_email' => ['sometimes', 'string', 'email:filter', 'max:254'],
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
            'plan' => ['sometimes', 'string', 'max:32'],
        ];
    }
}
