<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gapless per-tenant ticket numbers (docs/08-database/tenancy.md §Per-tenant counters).
 *
 * @property string $tenant_id
 * @property int $next_ticket_number
 */
final class TenantCounter extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_ticket_number' => 'integer', 'storage_used_bytes' => 'integer'];
    }
}
