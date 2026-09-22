<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Models\User;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Team;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Who a ticket notification goes to. Always active users of the current workspace, each at most once.
 */
final class Recipients
{
    /** The permission that marks "a manager" here: the people who can act on routing and breaches. */
    private const string MANAGER_PERMISSION = 'tickets.assign';

    /** @return Collection<int, User> */
    public function assignee(Ticket $ticket): Collection
    {
        if ($ticket->assigned_agent_id === null) {
            return new Collection;
        }

        return $this->usersOfAgents([$ticket->assigned_agent_id]);
    }

    /** @return Collection<int, User> the assignee, or the members of the ticket's team when nobody is assigned */
    public function assigneeOrTeam(Ticket $ticket): Collection
    {
        if ($ticket->assigned_agent_id !== null || $ticket->team_id === null) {
            return $this->assignee($ticket);
        }

        $team = Team::query()->with('agents')->find($ticket->team_id);

        return $this->usersOfAgents($team?->agents->modelKeys() ?? []);
    }

    /** @return Collection<int, User> */
    public function managers(): Collection
    {
        try {
            return User::permission(self::MANAGER_PERMISSION)->where('is_active', true)->get();
        } catch (PermissionDoesNotExist) {
            // The catalogue is not seeded (a fresh install before `db:seed`): nobody holds the permission.
            return new Collection;
        }
    }

    /**
     * Active users holding a permission, for example `mail.manage` for the workspace's mail admins.
     *
     * @return Collection<int, User>
     */
    public function withPermission(string $permission): Collection
    {
        try {
            return User::permission($permission)->where('is_active', true)->get();
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }
    }

    /**
     * @param  Collection<int, User>  ...$groups
     * @return Collection<int, User>
     */
    public function merge(Collection ...$groups): Collection
    {
        /** @var Collection<int, User> $all */
        $all = new Collection;
        foreach ($groups as $group) {
            $all = $all->merge($group);
        }

        return $all->unique('id')->values();
    }

    /**
     * @param  array<int, mixed>  $agentIds
     * @return Collection<int, User>
     */
    private function usersOfAgents(array $agentIds): Collection
    {
        $userIds = AgentProfile::query()->whereKey($agentIds)->pluck('user_id');

        return User::query()->whereKey($userIds)->where('is_active', true)->get();
    }
}
