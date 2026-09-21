<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain;

final readonly class MediaUsage
{
    public function __construct(
        public int $used_bytes,
        public int $quota_bytes,
        public int $pending_bytes,
    ) {}
}
