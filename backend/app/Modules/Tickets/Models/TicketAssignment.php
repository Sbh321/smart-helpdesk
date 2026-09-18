<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * History of who a ticket was assigned to, and why (the assignment explanation). Written by the
 * assignment actions in M2.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $reason
 * @property array<string, mixed> $explanation
 */
final class TicketAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['explanation' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
