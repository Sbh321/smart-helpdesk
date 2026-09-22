<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Resources;

use App\Modules\Mail\Models\InboundEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbound message with its text: the parsed reply, the full text part and the raw headers.
 * The HTML part is not returned; the text is what the helpdesk used.
 *
 * @mixin InboundEmail
 */
final class InboundEmailDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...(new InboundEmailResource($this->resource))->toArray($request),
            /** The new text of the reply after quotes and signature were cut (ReplyParser). */
            'reply_text' => $this->reply_text,
            'text_body' => $this->text_body,
            /**
             * Raw header values by lower-case name, as received.
             *
             * @var array<string, list<string>>
             */
            'headers' => (object) $this->headers,
            'raw_size' => $this->raw_size,
        ];
    }
}
