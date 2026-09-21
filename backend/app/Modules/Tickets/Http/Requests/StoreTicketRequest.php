<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Requests;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Tickets\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /v1/tickets`. The contact and category must belong to this workspace; the tenant scope
 * hides every other workspace's rows, so a foreign id fails exactly like an unknown one.
 */
final class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->filled('attachment_ids') || (bool) $this->user()?->can('media.view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'contact_id' => ['required', 'uuid', $this->existsInWorkspace(Contact::class, 'The selected contact does not exist.', activeOnly: true)],
            'category_id' => ['required', 'uuid', $this->existsInWorkspace(Category::class, 'The selected category does not exist.', activeOnly: true)],
            'impact' => ['required', 'integer', 'between:1,4'],
            'urgency' => ['required', 'integer', 'between:1,4'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'attachment_ids' => ['sometimes', 'array', 'max:50'],
            'attachment_ids.*' => ['uuid', 'distinct'],
        ];
    }

    /**
     * @param  class-string<Contact|Category>  $model
     */
    private function existsInWorkspace(string $model, string $message, bool $activeOnly): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $message, $activeOnly): void {
            if (! is_string($value)) {
                return;
            }

            $query = $model::query()->whereKey($value);

            if ($activeOnly) {
                $model === Contact::class
                    ? $query->whereNull('archived_at')
                    : $query->where('is_active', true);
            }

            if (! $query->exists()) {
                $fail($message);
            }
        };
    }
}
