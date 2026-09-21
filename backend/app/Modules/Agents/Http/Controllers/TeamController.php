<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Controllers;

use App\Modules\Agents\Contracts\DirectoryUsage;
use App\Modules\Agents\Domain\Exceptions\RecordInUse;
use App\Modules\Agents\Http\Requests\IndexDirectoryRequest;
use App\Modules\Agents\Http\Requests\ReplaceTeamMembersRequest;
use App\Modules\Agents\Http\Requests\SaveTeamRequest;
use App\Modules\Agents\Http\Resources\TeamResource;
use App\Modules\Agents\Models\Team;
use App\Modules\Audit\Audit;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('Agents')]
final class TeamController
{
    public function index(IndexDirectoryRequest $request): AnonymousResourceCollection
    {
        $query = Team::query()->with('agents.user');
        if (($search = $request->search()) !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }
        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return TeamResource::collection($query->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    #[ScrambleResponse(status: 201, type: TeamResource::class)]
    public function store(SaveTeamRequest $request): JsonResponse
    {
        $team = Team::query()->create($request->validated())->load('agents.user');

        return (new TeamResource($team))->response()->setStatusCode(201);
    }

    public function update(SaveTeamRequest $request, Team $team): TeamResource
    {
        $team->update($request->validated());

        return new TeamResource($team->refresh()->load('agents.user'));
    }

    public function replaceMembers(ReplaceTeamMembersRequest $request, Team $team, Clock $clock): TeamResource
    {
        /** @var list<string> $agentIds */
        $agentIds = $request->validated('agent_ids');
        sort($agentIds);

        return DB::transaction(function () use ($team, $agentIds, $clock): TeamResource {
            $locked = Team::query()->lockForUpdate()->findOrFail($team->id);
            $old = $locked->agents()->pluck('agent_profiles.id')->sort()->values()->all();
            // Kept members keep their joined_at; only new ones are stamped.
            $locked->agents()->detach(array_diff($old, $agentIds));
            $locked->agents()->attach(array_fill_keys(
                array_diff($agentIds, $old),
                ['tenant_id' => $locked->tenant_id, 'joined_at' => $clock->now()],
            ));
            Audit::record('team.members_changed', $locked, ['old' => $old, 'new' => $agentIds]);

            return new TeamResource($locked->load('agents.user'));
        });
    }

    public function destroy(Team $team, DirectoryUsage $usage): Response
    {
        $references = ['members' => $team->agents()->count(), ...$usage->teamReferences($team)];
        if (array_sum($references) > 0) {
            throw RecordInUse::for('team', $team->id, $references);
        }
        $team->delete();

        return response()->noContent();
    }
}
