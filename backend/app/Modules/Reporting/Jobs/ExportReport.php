<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Models\User;
use App\Modules\Media\Actions\StoreGeneratedFile;
use App\Modules\Media\Exceptions\QuotaExceeded;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Reporting\Events\ReportExportReady;
use App\Modules\Reporting\Exports\ExportFileWriter;
use App\Modules\Reporting\Exports\ExportParameters;
use App\Modules\Reporting\Exports\ExportTable;
use App\Modules\Reporting\Exports\ExportTables;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportRunner;
use App\Support\Time\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes one export (docs/04-domain/reporting.md §Exports as built): runs the catalogue report through
 * `ReportRunner` (so the file equals the report page) or the filtered ticket list through
 * `TicketListQuery`, streams CSV or XLSX to a temporary file, stores it as a Media item in the
 * system `Reports` folder under the workspace quota, and announces `ReportExportReady`. The
 * requester's permissions are checked again when the job runs. Queue `reports`.
 */
final class ExportReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10];

    /** Below the redis connection's retry_after (90 s), so a running export is never handed out twice. */
    public int $timeout = 80;

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue('reports');
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['reports', 'export', 'tenant:'.tenant()?->getTenantKey(), 'export:'.$this->exportId];
    }

    public function handle(
        ReportCatalogue $catalogue,
        ReportRunner $runner,
        ExportTables $tables,
        ExportFileWriter $writer,
        StoreGeneratedFile $store,
        Clock $clock,
    ): void {
        $export = ReportExport::query()->find($this->exportId);
        if ($export === null || in_array($export->state, ['ready', 'failed'], true)) {
            return;
        }
        $export->forceFill(['state' => 'running', 'started_at' => $clock->now()])->save();

        $user = User::query()->where('is_active', true)->find($export->requested_by_user_id);
        $table = $user === null ? null : $this->table($export, $user, $catalogue, $runner, $tables);
        if ($table === null) {
            $this->fail($export, 'forbidden', $clock);

            return;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'export-');
        try {
            $rows = $writer->write($table, $export->format, $path) - $table->summaryRows;
            clearstatcache(true, $path);
            if ((int) filesize($path) > AllowedMedia::maxBytes()) {
                $this->fail($export, 'too_large', $clock);

                return;
            }

            try {
                $item = $store($path, $this->filename($table, $export, $clock), $export->requested_by_user_id);
            } catch (QuotaExceeded) {
                $this->fail($export, 'quota_exceeded', $clock);

                return;
            }

            $export->forceFill([
                'state' => 'ready',
                'media_item_id' => $item->id,
                'row_count' => $rows,
                'error' => null,
                'finished_at' => $clock->now(),
            ])->save();
        } finally {
            @unlink($path);
        }

        event(new ReportExportReady($export->id, $export->requested_by_user_id));
    }

    public function failed(?Throwable $exception): void
    {
        ReportExport::query()->whereKey($this->exportId)->whereIn('state', ['queued', 'running'])
            ->update(['state' => 'failed', 'error' => 'failed', 'finished_at' => app(Clock::class)->now()]);
    }

    /** The table to write, or null when the requester may no longer export it. */
    private function table(ReportExport $export, User $user, ReportCatalogue $catalogue, ReportRunner $runner, ExportTables $tables): ?ExportTable
    {
        if (! $user->can('reports.export')) {
            return null;
        }
        $timezone = (string) (tenant('timezone') ?: 'UTC');

        if ($export->report_key === ReportExport::TICKETS_LIST) {
            if (! $user->can('tickets.view')) {
                return null;
            }

            return $tables->tickets(ExportParameters::ticketCriteria($export->parameters, $user->id), $timezone);
        }

        $report = $catalogue->find($export->report_key);
        if ($report === null || ! $catalogue->allows($user, $report)) {
            return null;
        }
        $parameters = $runner->parameters($report, $export->parameters, $export->tenant_id, $timezone);

        return $tables->report($report, $parameters, $runner->run($report, $parameters));
    }

    /** `Ticket volume 2026-09-21 1030.csv`, in the workspace time zone. */
    private function filename(ExportTable $table, ReportExport $export, Clock $clock): string
    {
        $stamp = $clock->now()->setTimezone((string) (tenant('timezone') ?: 'UTC'))->format('Y-m-d Hi');

        return "{$table->title} {$stamp}.{$export->format}";
    }

    private function fail(ReportExport $export, string $error, Clock $clock): void
    {
        $export->forceFill(['state' => 'failed', 'error' => $error, 'finished_at' => $clock->now()])->save();
    }
}
