<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One security-relevant action (docs/04-domain/audit.md). Rows are never updated or deleted.

 * Tenant scoping for the viewer (nullable tenant) is added with the tenant traits in M1-06.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property ActorType $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed> $changes
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Audit log entries are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Audit log entries are append-only.'));
    }
}
