<?php

declare(strict_types=1);

namespace App\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;

final class HealthController
{
    /**
     * Dependency health.
     *
     * Runs every check now and returns 200 when all checks are ok or warning, 503 when any fails.
     * Container health checks use `/up` instead, so a Valkey blip does not restart the app.
     */
    public function __invoke(ResultStore $store): JsonResponse
    {
        Artisan::call(RunHealthChecksCommand::class, ['--no-notification' => true]);

        $results = $store->latestResults();
        $checks = $results === null ? [] : $results->storedCheckResults->map(
            fn (StoredCheckResult $check): array => [
                'name' => $check->name,
                'label' => $check->label,
                'status' => $check->status,
                'summary' => $check->shortSummary,
                'message' => in_array($check->notificationMessage, [null, ''], true) ? null : $check->notificationMessage,
            ],
        )->values()->all();

        $statuses = array_column($checks, 'status');
        $status = match (true) {
            $checks === [] || array_intersect($statuses, ['failed', 'crashed']) !== [] => 'failed',
            in_array('warning', $statuses, true) => 'warning',
            default => 'ok',
        };

        return new JsonResponse([
            'data' => [
                'status' => $status,
                'checked_at' => $results?->finishedAt->format(DATE_ATOM),
                'checks' => $checks,
            ],
        ], $status === 'failed' ? 503 : 200, ['Cache-Control' => 'no-store']);
    }
}
