<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Modules\Reporting\Domain\History\EntityChange as RecordedChange;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One captured change (docs/08-database/entities.md §entity_changes). Rows are written only by the
 * `record_entity_change()` trigger and are never updated or deleted; the model is the read side
 * for history timelines and as-of replay (ADR-0022 §5).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_type
 * @property string $entity_id
 * @property int $version
 * @property string $operation
 * @property array<string, array{old?: mixed, new?: mixed}> $changes
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 */
final class EntityChange extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $table = 'entity_changes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id'];

    /**
     * The recorded diff. Eloquent keeps its own `$changes` property, so inside the model the column
     * is only reachable through `getAttribute()`.
     *
     * @return array<string, array{old?: mixed, new?: mixed}>
     */
    public function changedAttributes(): array
    {
        return (array) $this->getAttribute('changes');
    }

    /**
     * The domain value object the replayer works with.
     */
    public function toRecordedChange(): RecordedChange
    {
        return RecordedChange::fromStored($this->version, $this->operation, $this->occurred_at, $this->changedAttributes());
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'version' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(fn (): never => throw new LogicException('Entity changes are written by the database trigger only.'));
        self::deleting(fn (): never => throw new LogicException('Entity changes are append-only.'));
    }
}
