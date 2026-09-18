<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Worker and console correlation fields plus one `job.completed` / `job.failed` line per job.
 * The request id travels into jobs through Laravel's Context automatically.
 */
final class LogContext
{
    public static function register(Dispatcher $events): void
    {
        $events->listen(function (JobProcessing $event): void {
            Context::add([
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'attempt' => $event->job->attempts(),
            ]);
            Context::addHidden('job_started_at', hrtime(true));
        });

        $events->listen(function (JobProcessed $event): void {
            Log::info('job.completed', ['queue' => $event->job->getQueue(), 'duration_ms' => self::jobDuration()]);
            Context::forget(['job', 'job_id', 'attempt']);
        });

        $events->listen(function (JobFailed $event): void {
            Log::error('job.failed', [
                'queue' => $event->job->getQueue(),
                'duration_ms' => self::jobDuration(),
                'exception' => $event->exception::class,
            ]);
            Context::forget(['job', 'job_id', 'attempt']);
        });

        $events->listen(function (CommandStarting $event): void {
            // Artisan::call() inside a web request (the health report) must not relabel the request.
            if (app()->runningInConsole() && ! Context::has('request_id')) {
                // The command name is null when artisan runs without arguments.
                Context::add('command', $event->command ?? 'list');
            }
        });
    }

    private static function jobDuration(): ?int
    {
        $startedAt = Context::getHidden('job_started_at');

        return is_int($startedAt) ? intdiv(hrtime(true) - $startedAt, 1_000_000) : null;
    }
}
