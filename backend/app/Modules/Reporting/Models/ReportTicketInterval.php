<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTicketIntervalFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One maximal period of constant (status, assignee, team, priority) of a ticket. Written only by
 * `Support\TicketReportWriter`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property int $seq
 * @property string $status
 * @property string|null $assigned_agent_id
 * @property string|null $team_id
 * @property string $priority_level
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $wall_seconds
 * @property int|null $business_seconds
 */
#[UseFactory(ReportTicketIntervalFactory::class)]
final class ReportTicketInterval extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReportTicketIntervalFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'wall_seconds' => 'integer',
            'business_seconds' => 'integer',
        ];
    }
}
