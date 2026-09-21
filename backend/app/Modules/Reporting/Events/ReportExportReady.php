<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An export's file is stored and can be downloaded (docs/04-domain/notifications.md "Export ready").
 * Fired by `ExportReport` inside the workspace, after the export row is saved. Ids only.
 */
final readonly class ReportExportReady
{
    use Dispatchable;

    public function __construct(
        public string $exportId,
        public string $requestedByUserId,
    ) {}
}
