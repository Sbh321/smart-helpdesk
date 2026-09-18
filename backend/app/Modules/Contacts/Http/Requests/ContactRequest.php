<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Requests;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create and update a contact. Email is unique per workspace ignoring case; the organisation must
 * belong to the same workspace (the tenant scope makes another workspace's id invisible).
 */
final class ContactRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        /** @var Contact|null $contact */
        $contact = $this->route('contact');

        return [
            'name' => [$required, 'string', 'max:120'],
            'email' => [$required, 'string', 'email:filter', 'max:254', $this->uniqueEmail($contact)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+().\-\s]{3,32}$/'],
            'organization_id' => ['sometimes', 'nullable', 'uuid', $this->organizationInTenant()],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'external_ids' => ['sometimes', 'array', $this->maxJsonSize()],
            'metadata' => ['sometimes', 'array', $this->maxJsonSize()],
        ];
    }

    private function uniqueEmail(?Contact $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (! is_string($value)) {
                return;
            }

            $taken = Contact::query()
                ->whereRaw('lower(email) = lower(?)', [$value])
                ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
                ->exists();

            if ($taken) {
                $fail('A contact with this email already exists in this workspace.');
            }
        };
    }

    private function organizationInTenant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! Organization::query()->whereKey($value)->exists()) {
                $fail('The selected organisation does not exist.');
            }
        };
    }

    /**
     * `external_ids` and `metadata` are capped at 8 KB (docs/04-domain/contacts.md).
     */
    private function maxJsonSize(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (strlen((string) json_encode($value)) > 8192) {
                $fail('The :attribute may not be larger than 8 KB.');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return ['organization_id' => 'organisation'];
    }
}
