<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Notifications\Notifications\ExportReady;
use App\Modules\Reporting\Events\ReportExportReady;
use App\Modules\Reporting\Models\ReportExport;

/**
 * "Export ready" of docs/04-domain/notifications.md: the requester of the export, if still active.
 * Runs inside the workspace, so the queries are tenant-scoped.
 */
final readonly class SendExportNotifications
{
    public function ready(ReportExportReady $event): void
    {
        $export = ReportExport::query()->with('mediaItem')->find($event->exportId);
        $user = User::query()->where('is_active', true)->find($event->requestedByUserId);
        if ($export === null || $export->mediaItem === null || $user === null) {
            return;
        }

        $user->notify(new ExportReady($export->id, $export->mediaItem->id, $export->mediaItem->name, (int) $export->row_count));
    }
}
