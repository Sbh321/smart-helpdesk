<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaUses;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Reporting\Jobs\ExportReport;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use OpenSpout\Reader\XLSX\Reader;

require_once __DIR__.'/ReportingHelpers.php';
require_once __DIR__.'/../Media/MediaTestSupport.php';

// Report and ticket-list exports (roadmap M3-09, docs/04-domain/reporting.md §Exports as built).

beforeEach(function (): void {
    useMediaTestDisk();
    $this->app->instance(Clock::class, new FrozenClock('2026-09-21 06:00:00'));
    $this->tenant = createTenant('exports', ['timezone' => 'Asia/Kathmandu']);
    $this->tickets = randomHistories($this->tenant, 30, 7);
    $this->manager = actingAsRole($this->tenant, 'manager');
    tenancy()->initialize($this->tenant);
});

afterEach(fn () => cleanMediaTestDisk());

/** @return array{0: ReportExport, 1: MediaItem} the export, reloaded, and its file */
function readyExport(string $id): array
{
    $export = ReportExport::query()->with('mediaItem')->findOrFail($id);
    expect($export->state)->toBe('ready')->and($export->mediaItem)->not->toBeNull();

    return [$export, $export->mediaItem];
}

/** @return list<list<string>> */
function exportedCsv(Tenant $tenant, MediaItem $item): array
{
    $bytes = (string) file_get_contents(mediaObjectPath($tenant, $item->storage_key));
    expect(substr($bytes, 0, 3))->toBe("\xEF\xBB\xBF");
    $lines = preg_split('/\R/', rtrim(substr($bytes, 3)));

    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines ?: []);
}

/** @return list<list<mixed>> */
function exportedXlsx(Tenant $tenant, MediaItem $item): array
{
    $reader = new Reader;
    $reader->open(mediaObjectPath($tenant, $item->storage_key));
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    return $rows;
}

it('exports a report as CSV with the table of the report page and a Total row', function (): void {
    $parameters = ['period' => 'last_30d', 'group' => 'priority', 'measures' => 'created,resolved,reopened'];
    $run = $this->postJson('/v1/reports/rpt-t01/run', $parameters)->assertOk()->json('data');

    $response = $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv', 'parameters' => $parameters])
        ->assertStatus(202)
        ->assertJsonPath('data.report_key', 'rpt-t01')
        ->assertJsonPath('data.format', 'csv')
        ->assertJsonPath('data.download_url', null);
    $id = $response->json('data.id');

    $shown = $this->getJson("/v1/exports/{$id}")->assertOk()
        ->assertJsonPath('data.state', 'ready')
        ->assertJsonPath('data.row_count', count($run['rows']))
        ->json('data');
    [$export, $item] = readyExport($id);

    expect($shown['download_url'])->toEndWith("/v1/media/{$item->id}/download")
        ->and($shown['file_name'])->toBe('Ticket volume 2026-09-21 1145.csv')
        ->and($export->parameters)->toMatchArray(['from' => '2026-08-23', 'to' => '2026-09-21', 'period_name' => 'last_30d', 'group' => 'priority'])
        ->and($item->source)->toBe('system')
        ->and($item->uploaded_by_user_id)->toBe($this->manager->id)
        ->and($item->folder?->system_key)->toBe('reports')
        ->and($item->folder?->name)->toBe('Reports');

    $csv = exportedCsv($this->tenant, $item);
    expect($csv[0])->toBe(['Priority', 'Created', 'Resolved', 'Reopened'])
        ->and(count($csv))->toBe(count($run['rows']) + 2)
        ->and(end($csv))->toBe(['Total', (string) $run['totals']['created'], (string) $run['totals']['resolved'], (string) $run['totals']['reopened']])
        ->and($csv[1][0])->toBe($run['rows'][0]['label']);
});

it('exports a report as XLSX with numbers as numbers and the chosen measures only', function (): void {
    $run = $this->postJson('/v1/reports/rpt-t06/run', ['period' => 'last_30d', 'measures' => 'tickets,resolution_median'])->assertOk()->json('data');

    $id = $this->postJson('/v1/reports/rpt-t06/exports', ['format' => 'xlsx', 'parameters' => ['period' => 'last_30d', 'measures' => 'tickets,resolution_median']])
        ->assertStatus(202)->json('data.id');
    [, $item] = readyExport($id);

    $rows = exportedXlsx($this->tenant, $item);
    expect($item->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($rows[0])->toBe(['Priority', 'Tickets', 'Resolution, median (business) (seconds)'])
        ->and(count($rows))->toBe(count($run['rows']) + 2)
        ->and(end($rows)[0])->toBe('Total')
        ->and(end($rows)[1])->toBe($run['totals']['tickets']);
});

it('exports the filtered ticket list in the list order, as CSV and XLSX', function (): void {
    $expected = Ticket::query()->whereIn('status', ['resolved', 'closed'])->orderByDesc('number')->pluck('number')->all();
    expect($expected)->not->toBeEmpty();
    $body = ['filter' => ['status' => 'resolved,closed'], 'sort' => '-number'];

    $csvId = $this->postJson('/v1/exports/tickets', [...$body, 'format' => 'csv'])->assertStatus(202)
        ->assertJsonPath('data.report_key', 'tickets-list')->json('data.id');
    [$export, $item] = readyExport($csvId);
    $csv = exportedCsv($this->tenant, $item);

    expect($export->row_count)->toBe(count($expected))
        ->and($csv[0])->toBe(['Number', 'Title', 'Status', 'Priority', 'Impact', 'Urgency', 'Category', 'Team', 'Assignee',
            'Contact', 'Contact email', 'Organisation', 'Created', 'Updated', 'First response', 'Resolved', 'SLA state', 'SLA due'])
        ->and(array_map(fn (array $row): int => (int) $row[0], array_slice($csv, 1)))->toBe($expected)
        ->and($item->name)->toStartWith('Tickets ');

    $xlsxId = $this->postJson('/v1/exports/tickets', [...$body, 'format' => 'xlsx'])->assertStatus(202)->json('data.id');
    [, $xlsxItem] = readyExport($xlsxId);
    $rows = exportedXlsx($this->tenant, $xlsxItem);
    expect(count($rows))->toBe(count($expected) + 1)
        ->and(array_map(fn (array $row): int => (int) $row[0], array_slice($rows, 1)))->toBe($expected);
});

it('writes text as text: no formulas in CSV or XLSX', function (): void {
    $ticket = $this->tickets[0];
    Ticket::query()->whereKey($ticket->id)->update(['title' => '=HYPERLINK("http://evil.test","x")']);

    $csvId = $this->postJson('/v1/exports/tickets', ['format' => 'csv', 'filter' => ['number' => (string) $ticket->number]])->json('data.id');
    [, $item] = readyExport($csvId);
    expect(exportedCsv($this->tenant, $item)[1][1])->toBe('\'=HYPERLINK("http://evil.test","x")');

    $xlsxId = $this->postJson('/v1/exports/tickets', ['format' => 'xlsx', 'filter' => ['number' => (string) $ticket->number]])->json('data.id');
    [, $xlsxItem] = readyExport($xlsxId);
    $zip = new ZipArchive;
    $zip->open(mediaObjectPath($this->tenant, $xlsxItem->storage_key));
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    expect($sheet)->not->toContain('<f>')->and($sheet)->toContain('HYPERLINK');
});

it('notifies the requester in the app with the file', function (): void {
    $id = $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv'])->assertStatus(202)->json('data.id');
    [$export, $item] = readyExport($id);

    $stored = Notification::query()->where('notifiable_id', $this->manager->id)->where('type', 'export_ready')->sole();
    expect($stored->notification_key)->toBe("export_ready:{$id}");

    $this->getJson('/v1/notifications')->assertOk()
        ->assertJsonPath('data.0.kind', 'export_ready')
        ->assertJsonPath('data.0.export_id', $export->id)
        ->assertJsonPath('data.0.media_id', $item->id)
        ->assertJsonPath('data.0.file_name', $item->name)
        ->assertJsonPath('data.0.ticket_id', null);
});

it('lets only the requester see the export and download its file', function (): void {
    $id = $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv'])->assertStatus(202)->json('data.id');
    [, $item] = readyExport($id);
    $this->get("/v1/media/{$item->id}/download")->assertRedirect();

    // Another manager of the same workspace: the export and its file do not exist for them.
    actingAsRole($this->tenant, 'manager');
    $this->getJson("/v1/exports/{$id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson("/v1/media/{$item->id}/download")->assertNotFound();

    // The owner of another workspace.
    $other = createTenant('other');
    tenancy()->end();
    actingAsRole($other, 'owner');
    $this->getJson("/v1/exports/{$id}")->assertNotFound();
    $this->getJson("/v1/media/{$item->id}/download")->assertNotFound();
});

it('needs reports.export, and the report\'s own permissions', function (): void {
    actingAsRole($this->tenant, 'agent');
    $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv'])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->postJson('/v1/exports/tickets', ['format' => 'csv'])->assertForbidden();
    $this->getJson('/v1/exports/'.Str::uuid7())->assertForbidden();

    // A manager may export, but not a report that needs audit.view.
    actingAsRole($this->tenant, 'manager');
    $this->postJson('/v1/reports/rpt-g01/exports', ['format' => 'csv'])->assertForbidden();
    $this->postJson('/v1/reports/rpt-x99/exports', ['format' => 'csv'])->assertNotFound();
    expect(ReportExport::query()->count())->toBe(0);
});

it('validates the format, the report parameters and the ticket filters', function (): void {
    $this->postJson('/v1/reports/rpt-t01/exports', [])->assertUnprocessable()->assertJsonValidationErrors('format');
    $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'pdf'])->assertUnprocessable()->assertJsonValidationErrors('format');
    $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv', 'parameters' => ['period' => 'forever', 'group' => 'planet']])
        ->assertUnprocessable()->assertJsonValidationErrors(['parameters.period', 'parameters.group']);
    $this->postJson('/v1/exports/tickets', ['format' => 'csv', 'filter' => ['status' => 'lost']])
        ->assertUnprocessable()->assertJsonValidationErrors('filter.status');
    $this->postJson('/v1/exports/tickets', ['format' => 'csv', 'sort' => 'title'])->assertUnprocessable()->assertJsonValidationErrors('sort');
    expect(ReportExport::query()->count())->toBe(0);
});

it('fails the export when the file does not fit in the workspace quota, and keeps the quota', function (): void {
    Tenant::query()->whereKey($this->tenant->id)->update(['storage_quota_bytes' => 10]);

    $id = $this->postJson('/v1/exports/tickets', ['format' => 'csv'])->assertStatus(202)->json('data.id');

    $this->getJson("/v1/exports/{$id}")->assertOk()
        ->assertJsonPath('data.state', 'failed')
        ->assertJsonPath('data.error', 'quota_exceeded')
        ->assertJsonPath('data.download_url', null);
    expect(MediaItem::query()->count())->toBe(0)
        ->and(Notification::query()->where('type', 'export_ready')->count())->toBe(0);
});

it('counts the stored file against the workspace quota', function (): void {
    $id = $this->postJson('/v1/exports/tickets', ['format' => 'xlsx'])->assertStatus(202)->json('data.id');
    [, $item] = readyExport($id);

    expect((int) DB::table('tenant_counters')->where('tenant_id', $this->tenant->id)->value('storage_used_bytes'))->toBe($item->size_bytes)
        ->and($item->checksum_sha256)->toBe(hash_file('sha256', mediaObjectPath($this->tenant, $item->storage_key)));
});

it('checks the requester again when the job runs', function (): void {
    $user = createTenantUser($this->tenant);
    $this->tenant->run(fn () => $user->syncRoles(['agent']));
    $export = ReportExport::factory()->create(['requested_by_user_id' => $user->id, 'parameters' => []]);

    ExportReport::dispatchSync($export->id);

    expect($export->fresh()?->state)->toBe('failed')->and($export->fresh()?->error)->toBe('forbidden');

    $inactive = createTenantUser($this->tenant, ['is_active' => false]);
    $this->tenant->run(fn () => $inactive->syncRoles(['manager']));
    $other = ReportExport::factory()->create(['requested_by_user_id' => $inactive->id, 'report_key' => 'tickets-list', 'parameters' => []]);
    ExportReport::dispatchSync($other->id);
    expect($other->fresh()?->error)->toBe('forbidden');
});

it('does not write an export twice', function (): void {
    $export = ReportExport::factory()->create(['requested_by_user_id' => $this->manager->id, 'state' => 'ready', 'parameters' => []]);

    ExportReport::dispatchSync($export->id);

    expect(MediaItem::query()->count())->toBe(0)->and($export->fresh()?->state)->toBe('ready');
});

it('marks an export failed when the job gives up', function (): void {
    $export = ReportExport::factory()->create(['requested_by_user_id' => $this->manager->id, 'state' => 'running', 'parameters' => []]);

    (new ExportReport($export->id))->failed(new RuntimeException('boom'));

    expect($export->fresh()?->state)->toBe('failed')->and($export->fresh()?->error)->toBe('failed');
});

it('gives new workspaces the Reports system folder', function (): void {
    expect(DB::table('media_folders')->where('tenant_id', $this->tenant->id)->where('system_key', 'reports')->exists())->toBeFalse();
    // Provisioning creates it (ProvisionTenant); a workspace made by the factory gets it on its first export.
    $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv'])->assertStatus(202);
    expect(DB::table('media_folders')->where('tenant_id', $this->tenant->id)->where('system_key', 'reports')->value('name'))->toBe('Reports');
});

it('keeps generated files away from other users of the library', function (): void {
    $id = $this->postJson('/v1/reports/rpt-t01/exports', ['format' => 'csv'])->assertStatus(202)->json('data.id');
    [, $item] = readyExport($id);
    $reader = User::factory()->forTenant($this->tenant)->create();
    $this->tenant->run(fn () => $reader->syncRoles(['owner']));

    expect(app(MediaUses::class)->canDownload($reader, $item))->toBeFalse()
        ->and(app(MediaUses::class)->canDownload($this->manager, $item))->toBeTrue();
});
