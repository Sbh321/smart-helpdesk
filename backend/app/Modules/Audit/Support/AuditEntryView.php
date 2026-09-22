<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Models\AuditLog;

/** One audit entry as the viewer shows it: the stored row and the names of its actor and subject. */
final readonly class AuditEntryView
{
    public function __construct(
        public AuditLog $entry,
        public ?string $actorName,
        public ?string $subjectName,
    ) {}

    /** The cursor paginator reads `created_at` and `id` from the items it pages. */
    public function __get(string $name): mixed
    {
        return $this->entry->getAttribute($name);
    }

    public function __isset(string $name): bool
    {
        return $this->entry->getAttribute($name) !== null;
    }
}
