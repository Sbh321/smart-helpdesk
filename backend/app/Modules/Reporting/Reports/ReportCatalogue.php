<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Reports;

use App\Models\User;

/**
 * The catalogue (docs/04-domain/reporting.md §Report catalogue). Reports are listed here in catalogue
 * order; a caller sees the ones whose permissions they hold.
 */
final class ReportCatalogue
{
    /** @var list<class-string<ReportDefinition>> */
    public const array REPORTS = [
        Catalogue\TicketVolume::class,
        Catalogue\TicketBacklog::class,
        Catalogue\TimeInStatus::class,
        Catalogue\StatusFlow::class,
        Catalogue\Ageing::class,
        Catalogue\ResponseAndResolution::class,
        Catalogue\ReopensAndRework::class,
        Catalogue\AssignmentBehaviour::class,
        Catalogue\PriorityBehaviour::class,
        Catalogue\Duplicates::class,
        Catalogue\WorkloadHeatmap::class,
        Catalogue\ContactGrowth::class,
        Catalogue\TopRequesters::class,
        Catalogue\CustomerExperience::class,
        Catalogue\OrganisationChanges::class,
        Catalogue\AgentWorkload::class,
        Catalogue\AgentPerformance::class,
        Catalogue\Fairness::class,
        Catalogue\AvailabilityAndShifts::class,
        Catalogue\TeamComparison::class,
        Catalogue\SkillCoverage::class,
        Catalogue\SlaCompliance::class,
        Catalogue\BreachAnalysis::class,
        Catalogue\PauseBehaviour::class,
        Catalogue\AtRisk::class,
        Catalogue\EmailChannel::class,
        Catalogue\MediaUsage::class,
        Catalogue\AdministrativeActivity::class,
        Catalogue\ConfigurationHistory::class,
    ];

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        return array_map(fn (string $class): ReportDefinition => app($class), self::REPORTS);
    }

    public function find(string $key): ?ReportDefinition
    {
        foreach ($this->all() as $report) {
            if ($report->key() === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<ReportDefinition> */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (ReportDefinition $report): bool => $this->allows($user, $report)));
    }

    public function allows(User $user, ReportDefinition $report): bool
    {
        foreach (['reports.view', ...$report->permissions()] as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
