<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Tenancy\Rules\WorkspaceSlug;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'string', new WorkspaceSlug],
            'email' => ['required', 'string', 'email:filter', 'max:254'],
        ];
    }
}
