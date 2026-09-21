<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Requests;

use App\Modules\Integrations\Domain\Webhooks\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /v1/webhooks`. The URL is checked again by the SSRF guard (422 `webhook_url_rejected`).
 */
final class StoreWebhookRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventType::subscribableValues())],
        ];
    }
}
