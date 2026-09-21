<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Http\Resources\ReportDefinitionResource;
use App\Modules\Reporting\Http\Resources\ReportRecordResource;
use App\Modules\Reporting\Http\Resources\ReportRunResource;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportDefinition;
use App\Modules\Reporting\Reports\ReportDescription;
use App\Modules\Reporting\Reports\ReportRecord;
use App\Modules\Reporting\Reports\ReportRun;
use App\Modules\Reporting\Reports\ReportRunner;
use App\Modules\Reporting\Reports\SqlReport;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The report catalogue (docs/04-domain/reporting.md, docs/07-api/conventions.md §Reports).
 * Parameters: `period` (today, yesterday, last_7d, last_30d, last_90d, this_month, last_month, this_year)
 * or `from` and `to` (YYYY-MM-DD, inclusive, workspace time zone); `group`; `measures` (comma list);
 * `filter[name]` (comma list, `none` for empty); `compare` (true: totals of the previous period).
 */
#[Group('Reports')]
final class ReportController
{
    public function __construct(private readonly ReportCatalogue $catalogue, private readonly ReportRunner $runner) {}

    /** The reports the caller may run. */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return ReportDefinitionResource::collection(array_map(ReportDescription::of(...), $this->catalogue->visibleTo($user)));
    }

    /** Get a report definition. */
    public function show(Request $request, string $report): ReportDefinitionResource
    {
        return new ReportDefinitionResource(ReportDescription::of($this->report($request, $report)));
    }

    /**
     * Run a report.
     *
     * Rows per value of the chosen dimension, totals, and the previous period's totals.
     */
    #[BodyParameter('period', 'today, yesterday, last_7d, last_30d (default), last_90d, this_month, last_month or this_year.', type: 'string')]
    #[BodyParameter('from', 'First day (YYYY-MM-DD, workspace time zone); with `to` it replaces `period`.', type: 'string')]
    #[BodyParameter('to', 'Last day, inclusive (YYYY-MM-DD).', type: 'string')]
    #[BodyParameter('group', 'A dimension key of the report; its default dimension when omitted.', type: 'string')]
    #[BodyParameter('measures', 'Comma list of measure keys; all when omitted.', type: 'string')]
    #[BodyParameter('filter', 'Filter key => comma list of values (`none` for empty).', type: 'array<string, string>')]
    #[BodyParameter('compare', 'Also return the totals of the previous period of the same length.', type: 'bool')]
    public function run(Request $request, string $report): ReportRunResource
    {
        $definition = $this->report($request, $report);
        $parameters = $this->runner->parameters($definition, (array) $request->all(), (string) tenant()?->getTenantKey(), $this->timezone());
        $result = $this->runner->run($definition, $parameters);

        return new ReportRunResource(new ReportRun(
            $definition->key(), $parameters->toArray(), $result->rows, $result->totals, $result->previous, $result->truncated,
        ));
    }

    /**
     * List the records behind a report number.
     *
     * The records behind one number: `key` is the dimension value of the row (omit it for the totals).
     */
    #[QueryParameter('period', 'As for the run.', type: 'string')]
    #[QueryParameter('from', 'As for the run.', type: 'string')]
    #[QueryParameter('to', 'As for the run.', type: 'string')]
    #[QueryParameter('group', 'As for the run: the dimension `key` belongs to.', type: 'string')]
    #[QueryParameter('filter', 'As for the run.', type: 'array<string, string>')]
    #[QueryParameter('key', 'The row key to drill into; omitted for the totals.', type: 'string')]
    #[QueryParameter('page', 'One-based page number.', type: 'integer')]
    public function records(Request $request, string $report): AnonymousResourceCollection
    {
        $definition = $this->report($request, $report);
        abort_unless($definition instanceof SqlReport && $definition->drillDownTo() !== null, 404);

        $parameters = $this->runner->parameters($definition, (array) $request->query(), (string) tenant()?->getTenantKey(), $this->timezone());
        $page = max(1, $request->integer('page', 1));
        $key = $request->query('key');
        $found = $definition->records($parameters, is_string($key) ? $key : null, $page, 50);

        return ReportRecordResource::collection($this->describe((string) $definition->drillDownTo(), $found['ids']))
            ->additional(['meta' => ['entity' => $definition->drillDownTo(), 'current_page' => (int) $page, 'per_page' => 50, 'total' => (int) $found['total']]]);
    }

    private function report(Request $request, string $key): ReportDefinition
    {
        $report = $this->catalogue->find($key);
        abort_if($report === null, 404);
        /** @var User $user */
        $user = $request->user();
        if (! $this->catalogue->allows($user, $report)) {
            throw new AuthorizationException;
        }

        return $report;
    }

    private function timezone(): string
    {
        return (string) (tenant('timezone') ?: 'UTC');
    }

    /**
     * Enough of each record to list it and link to its page, in the order of the ids.
     *
     * @param  list<string>  $ids
     * @return list<ReportRecord>
     */
    private function describe(string $entity, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $tenant = tenant()?->getTenantKey();
        $records = match ($entity) {
            'tickets' => DB::table('tickets')->whereIn('id', $ids)->where('tenant_id', $tenant)->get(['id', 'number', 'title', 'status'])
                ->map(fn (object $row): ReportRecord => new ReportRecord($row->id, 'tickets', "#{$row->number} {$row->title}", null, $row->status)),
            'contacts' => DB::table('contacts')->whereIn('id', $ids)->where('tenant_id', $tenant)->get(['id', 'name', 'email'])
                ->map(fn (object $row): ReportRecord => new ReportRecord($row->id, 'contacts', (string) $row->name, $row->email)),
            'organizations' => DB::table('organizations')->whereIn('id', $ids)->where('tenant_id', $tenant)->get(['id', 'name', 'tier'])
                ->map(fn (object $row): ReportRecord => new ReportRecord($row->id, 'organizations', (string) $row->name, $row->tier)),
            'agents' => DB::table('agent_profiles as a')->join('users as u', 'u.id', '=', 'a.user_id')->whereIn('a.id', $ids)->where('a.tenant_id', $tenant)
                ->get(['a.id', 'u.name', 'u.email'])->map(fn (object $row): ReportRecord => new ReportRecord($row->id, 'agents', (string) $row->name, $row->email)),
            default => collect($ids)->map(fn (string $id): ReportRecord => new ReportRecord($id, $entity, $id)),
        };
        $byId = $records->keyBy('id');

        return array_values(array_filter(array_map(fn (string $id): ?ReportRecord => $byId[$id] ?? null, $ids)));
    }
}
