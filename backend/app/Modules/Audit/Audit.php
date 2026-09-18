<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Actions\RecordAuditLog;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Public entry point of the Audit module (docs/03-architecture/backend.md §Modules).
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $changes
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $tenantId = null,
        ?ActorType $actorType = null,
        ?string $actorId = null,
    ): AuditLog {
        return app(RecordAuditLog::class)($action, $subject, $changes, $tenantId, $actorType, $actorId);
    }
}
