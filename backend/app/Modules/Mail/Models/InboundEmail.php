<?php

declare(strict_types=1);

namespace App\Modules\Mail\Models;

use App\Modules\Mail\Enums\InboundState;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fetched inbound message and what became of it (docs/04-domain/email.md §Inbound pipeline).
 *
 * Nullable tenant, like `AuditLog`: the row is created inside the workspace it was routed to (or
 * centrally, with no tenant, when it names none) and never moves. No `BelongsToTenant` scope, because
 * the trait would fill a tenant into platform rows; queries name the workspace and row-level
 * security hides everything else.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $message_id
 * @property string|null $from_address
 * @property string|null $from_name
 * @property list<string> $to_addresses
 * @property list<string> $cc_addresses
 * @property string $subject
 * @property array<string, list<string>> $headers
 * @property string|null $text_body
 * @property string|null $html_body
 * @property string|null $reply_text
 * @property InboundState $state
 * @property string|null $route
 * @property string|null $reason
 * @property string|null $ticket_id
 * @property string|null $comment_id
 * @property string|null $contact_id
 * @property list<array{name: string, size: int, media_id: string|null, skipped: string|null}> $attachments
 * @property string|null $raw_key
 * @property int $raw_size
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $processed_at
 * @property CarbonImmutable $created_at
 * @property-read Ticket|null $ticket
 */
final class InboundEmail extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = [
        'to_addresses' => '[]',
        'cc_addresses' => '[]',
        'headers' => '{}',
        'attachments' => '[]',
    ];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    protected function casts(): array
    {
        return [
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'headers' => 'array',
            'attachments' => 'array',
            'state' => InboundState::class,
            'raw_size' => 'integer',
            'sent_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
