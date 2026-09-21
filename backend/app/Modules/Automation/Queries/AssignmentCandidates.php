<?php

declare(strict_types=1);

namespace App\Modules\Automation\Queries;

use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\AgentShift;
use App\Modules\Automation\Domain\Assignment\AgentCandidate;
use App\Modules\Automation\Domain\Assignment\TicketNeeds;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The candidate loader of docs/05-algorithms/agent-assignment.md: turns the tenant's agent
 * directory into `TicketNeeds` + `AgentCandidate`s. It decides nothing; the `AssignmentStrategy`
 * does.
 *
 * `openTickets` is the stored `agent_profiles.active_ticket_count` (see AgentWorkloadCounter for
 * who maintains it). With `$lock` the agent rows are read `FOR UPDATE` in id order, so the counts
 * cannot change until the caller's transaction ends.
 */
final readonly class AssignmentCandidates
{
    public function __construct(private Clock $clock, private Settings $settings) {}

    public function forTicket(Ticket $ticket, bool $lock = false): AgentPool
    {
        $ticket->loadMissing('category.skills');
        $enforceShifts = $this->shiftsEnforced();

        $profiles = AgentProfile::query()
            ->with(['user', 'skills', 'teams', 'shifts'])
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();

        $skills = array_values($ticket->category->skills->pluck('slug')->sort()->values()->all());
        $teamId = $ticket->team_id ?? $ticket->category->default_team_id;

        $candidates = [];
        foreach ($profiles as $profile) {
            $candidates[] = new AgentCandidate(
                id: $profile->id,
                openTickets: $profile->active_ticket_count,
                capacity: $profile->capacity,
                skills: array_values($profile->skills->pluck('slug')->sort()->values()->all()),
                teamIds: array_values($profile->teams->pluck('id')->sort()->values()->all()),
                active: $profile->user->is_active,
                available: $profile->availability === AgentAvailability::Available,
                onShift: ! $enforceShifts || $this->onShift($profile->shifts),
                lastAssignedAt: $profile->last_assigned_at,
                name: $profile->user->name,
            );
        }

        return new AgentPool(new TicketNeeds($ticket->id, $skills, $teamId, $enforceShifts), $candidates);
    }

    private function shiftsEnforced(): bool
    {
        return (bool) $this->settings->get('shifts.enforce', false);
    }

    /** @param Collection<int, AgentShift> $shifts */
    private function onShift(Collection $shifts): bool
    {
        $localNow = $this->clock->now()->setTimezone((string) (tenant('timezone') ?? 'UTC'));
        $exceptions = $shifts->filter(
            fn (AgentShift $shift): bool => $shift->date?->isSameDay($localNow) === true,
        );

        if ($exceptions->isNotEmpty()) {
            if ($exceptions->contains(fn (AgentShift $shift): bool => $shift->is_off)) {
                return false;
            }

            return $exceptions->contains(fn (AgentShift $shift): bool => $this->contains($shift, $localNow));
        }

        return $shifts->contains(fn (AgentShift $shift): bool => $shift->weekday === $localNow->dayOfWeek
            && ! $shift->is_off
            && $this->contains($shift, $localNow));
    }

    private function contains(AgentShift $shift, CarbonImmutable $at): bool
    {
        $time = $at->format('H:i:s');

        return $time >= $shift->starts_at && $time < $shift->ends_at;
    }
}
