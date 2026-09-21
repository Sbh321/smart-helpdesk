<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /v1/webhooks/{webhook}`: any of name, URL and events. A changed URL passes the SSRF guard.
 */
final class UpdateWebhookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'url' => ['sometimes', 'required', 'string', 'max:2048', 'url:http,https'],
            'events' => ['sometimes', 'required', 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventType::subscribableValues())],
        ];
    }
}
