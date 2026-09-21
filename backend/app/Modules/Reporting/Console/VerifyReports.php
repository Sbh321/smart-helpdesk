<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Models\ReportTicketFact;
use App\Modules\Reporting\Models\ReportTicketInterval;
use App\Modules\Reporting\Support\DailySnapshotBuilder;
use App\Modules\Reporting\Support\TicketReportWriter;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compares stored read models with a fresh computation (reporting.md §Operations): a sample of tickets
 * (intervals and facts) and of snapshot days. Prints every drift and fails when there is any.
 */
final class VerifyReports extends Command
{
    protected $signature = 'reports:verify {--tenant= : Limit to a workspace slug} {--sample=50 : Tickets per workspace} {--days=5 : Snapshot days per workspace}';

    protected $description = 'Check derived report tables against a fresh computation';

    public function handle(TicketReportWriter $writer, DailySnapshotBuilder $snapshots): int
    {
        $tenants = Tenant::active();
        if (is_string($slug = $this->option('tenant')) && $slug !== '') {
            $tenants->where('slug', $slug);
        }
        $drift = 0;

        foreach ($tenants->cursor() as $tenant) {
            $drift += (int) $tenant->run(function () use ($tenant, $writer, $snapshots): int {
                $found = 0;
                $sample = Ticket::query()->inRandomOrder()->limit(max(1, (int) $this->option('sample')))->get();
                foreach ($sample as $ticket) {
                    $fresh = $writer->compute($ticket);
                    $stored = ReportTicketInterval::query()->where('ticket_id', $ticket->id)->orderBy('seq')->get()
                        ->map(fn (ReportTicketInterval $row): array => array_intersect_key($row->getAttributes(), $fresh->intervals[0] ?? []))
                        ->all();
                    if ($this->normalise($stored) !== $this->normalise($fresh->intervals)) {
                        $this->components->error("{$tenant->slug}: intervals of ticket #{$ticket->number} differ");
                        $found++;
                    }
                    $fact = ReportTicketFact::query()->where('ticket_id', $ticket->id)->first();
                    $storedFact = $fact === null ? [] : array_intersect_key($fact->getAttributes(), $fresh->facts);
                    if ($this->normalise($storedFact) !== $this->normalise($fresh->facts)) {
                        $this->components->error("{$tenant->slug}: facts of ticket #{$ticket->number} differ");
                        $found++;
                    }
                }

                $timezone = (string) ($tenant->timezone ?: 'UTC');
                $days = DB::table('report_daily_snapshots')->distinct()->orderByDesc('day')
                    ->limit(max(1, (int) $this->option('days')))->pluck('day');
                foreach ($days as $day) {
                    $date = CarbonImmutable::parse((string) $day, $timezone);
                    // Both sides are sorted in PHP: the database collation orders `-` and UUIDs differently.
                    $storedRows = DB::table('report_daily_snapshots')->where('day', $date->toDateString())->get()
                        ->map(fn (object $row): array => [
                            'dimension' => $row->dimension,
                            'dimension_key' => $row->dimension_key,
                            'metrics' => json_decode((string) $row->metrics, true),
                        ])
                        ->sortBy(fn (array $row): string => $row['dimension'].'|'.$row['dimension_key'], SORT_STRING)->values()->all();
                    $freshRows = collect($snapshots->compute((string) $tenant->id, $date, $timezone))
                        ->sortBy(fn (array $row): string => $row['dimension'].'|'.$row['dimension_key'], SORT_STRING)->values()->all();
                    if ($this->normalise($storedRows) !== $this->normalise($freshRows)) {
                        $this->components->error("{$tenant->slug}: snapshot of {$date->toDateString()} differs");
                        $found++;
                    }
                }

                return $found;
            });
        }

        $drift === 0
            ? $this->components->info('Report tables match a fresh computation.')
            : $this->components->error("{$drift} differences found.");

        return $drift === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Same shape for stored and computed values: dates as UTC timestamps, numbers as numbers, sorted keys. */
    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalised = array_map(fn (mixed $item): mixed => $this->normalise($item), $value);
            if (! array_is_list($normalised)) {
                ksort($normalised);
            }

            return $normalised;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1) {
            return CarbonImmutable::parse($value)->getTimestamp();
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }
}
