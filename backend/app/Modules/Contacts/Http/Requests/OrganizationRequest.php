<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Requests;

use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Contacts\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrganizationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var Organization|null $organization */
        $organization = $this->route('organization');

        return [
            'name' => [$required, 'string', 'max:120', $this->uniqueName($organization)],
            'domain' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'tier' => ['sometimes', Rule::enum(OrganizationTier::class)],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'external_ids' => ['sometimes', 'array'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    private function uniqueName(?Organization $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            $taken = is_string($value) && Organization::query()
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->exists();

            if ($taken) {
                $fail('An organisation with this name already exists in this workspace.');
            }
        };
    }
}
