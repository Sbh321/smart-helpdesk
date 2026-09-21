<?php

declare(strict_types=1);

namespace App\Modules\Automation\Support;

use App\Modules\Agents\Contracts\DirectoryUsage;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Automation\Queries\AgentWorkload;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;

/**
 * Answers the Agent directory's questions about tickets and categories; every query runs through
 * tenant-scoped models.
 */
final readonly class TicketDirectoryUsage implements DirectoryUsage
{
    public function __construct(private AgentWorkload $workload) {}

    public function activeTicketCount(AgentProfile $agent): int
    {
        return Ticket::query()
            ->where('assigned_agent_id', $agent->id)
            ->whereIn('status', TicketStatus::active())
            ->count();
    }

    public function workload(AgentProfile $agent): array
    {
        return $this->workload->for($agent);
    }

    public function teamReferences(Team $team): array
    {
        return [
            'tickets' => Ticket::query()->where('team_id', $team->id)->count(),
            'categories' => Category::query()->where('default_team_id', $team->id)->count(),
        ];
    }

    public function skillReferences(Skill $skill): array
    {
        return [
            'categories' => Category::query()->whereHas('skills', fn ($skills) => $skills->whereKey($skill->id))->count(),
        ];
    }
}
