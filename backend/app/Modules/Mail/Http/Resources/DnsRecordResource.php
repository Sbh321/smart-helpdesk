<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Resources;

use App\Modules\Mail\Support\DnsRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A DNS record the operator publishes for the mail domain.
 *
 * @mixin DnsRecord
 */
final class DnsRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** @var 'MX'|'TXT' */
            'type' => $this->type,
            'name' => $this->name,
            'value' => $this->value,
            'purpose' => $this->purpose,
            /** False while a value is unknown (the DKIM key before mail-init.sh has run). */
            'ready' => $this->ready,
        ];
    }
}
