<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Support\PlatformPass;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * The start page of the monitor host (ADR-0024): what is there and a link to each tool. Only the tools
 * this installation runs are listed (Telescope exists only in local; the mail link is Mailpit there and
 * the mail server's administration elsewhere).
 */
final class MonitorHomeController
{
    public function __invoke(): Response
    {
        $tools = [
            ['Horizon', '/horizon', 'Queues: throughput, wait times, recent and failed jobs, per-workspace tags.'],
            ['Health', '/health', 'The latest results of the scheduled health checks: database, cache, queues, storage, SLA sweep, disk.'],
        ];
        if (Route::has('telescope')) {
            $tools[] = ['Telescope', '/telescope', 'Requests, queries, jobs, mail and exceptions of this development instance.'];
        }
        $tools[] = ['Object storage', '/storage', 'The RustFS console; sign in with the storage access key and secret.'];
        $tools[] = app()->environment('local')
            ? ['Mailpit', 'https://mail.'.config('helpdesk.platform_domain'), 'Every email this development instance sent.']
            : ['Mail server', 'https://mail.'.config('helpdesk.platform_domain'), 'The Stalwart web administration; sign in as the mail administrator.'];
        $links = [
            ['Platform console', 'https://'.config('helpdesk.hosts.admin').'/platform/tenants'],
            ['Platform docs', 'https://'.PlatformPass::host('docs').'/'],
        ];

        return response()->view('platform.monitor-home', ['tools' => $tools, 'links' => $links, 'app' => config('app.name')])
            ->header('Cache-Control', 'no-store');
    }
}
