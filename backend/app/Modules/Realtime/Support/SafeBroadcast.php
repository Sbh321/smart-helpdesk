<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a broadcast and never fails the caller: a stopped Reverb must not fill `failed_jobs` or break
 * a notification job. The SPA polls while the socket is down, so a lost broadcast only costs latency.
 */
final class SafeBroadcast
{
    public static function send(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            Log::warning('realtime.broadcast_failed', [
                'event' => $event::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
