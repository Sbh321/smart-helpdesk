<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Dashboard\Dashboard;
use App\Modules\Reporting\Http\Resources\DashboardResource;
use App\Modules\Reporting\Reports\ReportRunner;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `GET /v1/dashboard`: the management dashboard (FR-ANL), composed from catalogue reports
 * (docs/04-domain/reporting.md). Tiles and series of reports the caller may not run are left out.
 */
#[Group('Reports')]
final class DashboardController
{
    public function __construct(private readonly Dashboard $dashboard) {}

    /** Get the dashboard. */
    public function __invoke(Request $request): DashboardResource
    {
        $validated = $request->validate([
            /** Preset period; defaults to last_30d. */
            'period' => ['sometimes', 'string', Rule::in(ReportRunner::PERIODS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        return new DashboardResource($this->dashboard->for(
            $user,
            (string) ($validated['period'] ?? 'last_30d'),
            (string) tenant()?->getTenantKey(),
            (string) (tenant('timezone') ?: 'UTC'),
        ));
    }
}
