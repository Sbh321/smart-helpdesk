<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Exports\ExportParameters;
use App\Modules\Reporting\Exports\ExportTables;
use App\Modules\Reporting\Http\Requests\ExportTicketsRequest;
use App\Modules\Reporting\Http\Requests\StoreReportExportRequest;
use App\Modules\Reporting\Http\Resources\ReportExportResource;
use App\Modules\Reporting\Jobs\ExportReport;
use App\Modules\Reporting\Models\ReportExport;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportRunner;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Report and ticket-list exports (docs/04-domain/reporting.md §Exports, docs/07-api/conventions.md).
 * A request is validated now and written by the queued `ExportReport` job; the caller polls
 * `GET /v1/exports/{export}` and is notified ("Export ready") when the file is stored.
 */
#[Group('Reports')]
final class ExportController
{
    /**
     * Export a report.
     *
     * Queues a CSV or XLSX file of the report's data table: one row per group, the measures asked
     * for and a `Total` row. The period is fixed when the export is requested.
     */
    #[Response(status: 202, type: ReportExportResource::class)]
    public function report(StoreReportExportRequest $request, string $report, ReportCatalogue $catalogue, ReportRunner $runner): JsonResponse
    {
        $definition = $catalogue->find($report);
        abort_if($definition === null, 404);
        /** @var User $user */
        $user = $request->user();
        if (! $catalogue->allows($user, $definition)) {
            throw new AuthorizationException;
        }

        try {
            $parameters = $runner->parameters($definition, (array) $request->input('parameters', []), (string) tenant()?->getTenantKey(), $this->timezone());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors["parameters.{$key}"] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        return $this->queue($user, $definition->key(), ExportParameters::forReport($parameters), (string) $request->string('format'));
    }

    /**
     * Export the ticket list.
     *
     * Queues a CSV or XLSX file of the ticket list with the filters, search and sort of
     * `GET /v1/tickets` (in the body), one ticket per row. At most 50 000 tickets: a larger list
     * answers 422 on `filter`.
     */
    #[Response(status: 202, type: ReportExportResource::class)]
    public function tickets(ExportTicketsRequest $request, ExportTables $tables): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $criteria = $request->criteria();

        $count = $tables->ticketCount($criteria);
        if ($count > ExportTables::TICKET_ROW_CAP) {
            throw ValidationException::withMessages([
                'filter' => sprintf('The list has %d tickets; narrow the filters to at most %d.', $count, ExportTables::TICKET_ROW_CAP),
            ]);
        }

        return $this->queue($user, ReportExport::TICKETS_LIST, ExportParameters::forTickets($criteria), (string) $request->string('format'));
    }

    /**
     * Show one of my exports.
     *
     * Only the requester sees an export; anyone else gets 404. When `state` is `ready`,
     * `download_url` points at the Media download route.
     */
    public function show(Request $request, string $export): ReportExportResource
    {
        $found = ReportExport::query()->with('mediaItem')
            ->where('requested_by_user_id', $request->user()?->getAuthIdentifier())
            ->find($export);
        abort_if($found === null, 404);

        return new ReportExportResource($found);
    }

    /** @param array<string, mixed> $parameters */
    private function queue(User $user, string $reportKey, array $parameters, string $format): JsonResponse
    {
        $export = new ReportExport;
        $export->forceFill([
            'report_key' => $reportKey,
            'parameters' => $parameters,
            'format' => $format,
            'state' => 'queued',
            'requested_by_user_id' => $user->id,
        ])->save();

        ExportReport::dispatch($export->id);

        return (new ReportExportResource($export->setRelation('mediaItem', null)))->response()->setStatusCode(202);
    }

    private function timezone(): string
    {
        return (string) (tenant('timezone') ?: 'UTC');
    }
}
