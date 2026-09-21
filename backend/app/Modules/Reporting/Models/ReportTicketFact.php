<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTicketFactFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lifecycle measures of one ticket (docs/08-database/entities.md §report_ticket_facts). Written only by
 * `Support\TicketReportWriter`.
 *
 * @property string $id
 * @property string $ticket_id
 * @property string $tenant_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property string $priority_level
 * @property string $initial_priority_level
 * @property int|null $first_response_wall_s
 * @property int|null $resolution_wall_s
 * @property int $pending_s
 * @property int $unassigned_s
 * @property int $reassign_count
 * @property string|null $first_response_sla
 * @property string|null $resolution_sla
 * @property CarbonImmutable $refreshed_at
 */
#[UseFactory(ReportTicketFactFactory::class)]
final class ReportTicketFact extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportTicketFactFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
            'priority_overridden' => 'boolean',
            'closed_as_duplicate' => 'boolean',
        ];
    }
}
