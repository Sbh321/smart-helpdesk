<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Webhooks;

/**
 * `pending`: queued for its next attempt. `failed`: the last attempt failed and a retry is scheduled
 * at `next_attempt_at`. `succeeded` and `dead` are final (a manual retry reopens `failed`/`dead`).
 */
enum DeliveryState: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Dead = 'dead';
}
