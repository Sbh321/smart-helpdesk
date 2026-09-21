<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Controllers;

use App\Models\User;
use App\Modules\Agents\Contracts\DirectoryUsage;
use App\Modules\Agents\Domain\Exceptions\RecordInUse;
use App\Modules\Agents\Http\Requests\IndexAgentsRequest;
use App\Modules\Agents\Http\Requests\SaveAgentRequest;
use App\Modules\Agents\Http\Requests\UpdateAgentRequest;
use App\Modules\Agents\Http\Resources\AgentResource;
use App\Modules\Agents\Http\Resources\AgentWorkloadResource;
use App\Modules\Agents\Http\Resources\AvailableUserResource;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Support\AgentWorkloadView;
use App\Modules\Audit\Audit;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

#[Group('Agents')]
final class AgentController
{
    public function __construct(private readonly DirectoryUsage $usage, private readonly Clock $clock) {}

    /** List agents. */
    public function index(IndexAgentsRequest $request): AnonymousResourceCollection
    {
        $query = AgentProfile::query()->with(['user', 'skills', 'teams']);
        $query->when($request->search(), fn (Builder $q, string $search) => $q->whereHas(
            'user', fn (Builder $user) => $user->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
        ));
        $query->when($request->filterValues('availability'), fn (Builder $q, array $values) => $q->whereIn('availability', $values));
        $query->when($request->filterValues('team_id'), fn (Builder $q, array $values) => $q->whereHas('teams', fn (Builder $teams) => $teams->whereIn('teams.id', $values)));
        $query->when($request->filterValues('skill_id'), fn (Builder $q, array $values) => $q->whereHas('skills', fn (Builder $skills) => $skills->whereIn('skills.id', $values)));
        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return AgentResource::collection($query->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    /** List users who can become agents. */
    public function availableUsers(): AnonymousResourceCollection
    {
        return AvailableUserResource::collection(
            User::query()->where('is_active', true)->whereDoesntHave('agentProfile')->orderBy('name')->get(),
        );
    }

    /** Make a user an agent. */
    #[Response(status: 201, type: AgentResource::class)]
    public function store(SaveAgentRequest $request): JsonResponse
    {
        $agent = $this->save($request->validated(), new AgentProfile);

        return (new AgentResource($agent))->response()->setStatusCode(201);
    }

    /** Get an agent. */
    public function show(AgentProfile $agent): AgentResource
    {
        return new AgentResource($this->loadForResource($agent));
    }

    /** Update an agent. */
    public function update(UpdateAgentRequest $request, AgentProfile $agent): AgentResource
    {
        return new AgentResource($this->save($request->validated(), $agent));
    }

    /** Get an agent's live workload. */
    public function workload(AgentProfile $agent): AgentWorkloadResource
    {
        return new AgentWorkloadResource(AgentWorkloadView::fromArray($this->usage->workload($agent)));
    }

    /** Remove an agent profile. */
    public function destroy(AgentProfile $agent): HttpResponse
    {
        $tickets = $this->usage->activeTicketCount($agent);
        if ($tickets > 0) {
            throw RecordInUse::for('agent', $agent->id, ['tickets' => $tickets]);
        }

        $agent->delete();

        return response()->noContent();
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, AgentProfile $agent): AgentProfile
    {
        return DB::transaction(function () use ($data, $agent): AgentProfile {
            $before = $agent->exists ? $agent->only(['user_id', 'capacity', 'availability']) : [];
            $agent->fill(Arr::except($data, ['skills', 'team_ids']))->save();

            if (array_key_exists('skills', $data)) {
                /** @var list<array{skill_id:string,level:int}> $skills */
                $skills = $data['skills'];
                $sync = [];
                foreach ($skills as $skill) {
                    $sync[$skill['skill_id']] = ['tenant_id' => $agent->tenant_id, 'level' => $skill['level']];
                }
                $agent->skills()->sync($sync);
            }
            if (array_key_exists('team_ids', $data)) {
                /** @var list<string> $teams */
                $teams = $data['team_ids'];
                // Kept memberships keep their joined_at; only new ones are stamped.
                $current = $agent->teams()->pluck('teams.id')->all();
                $agent->teams()->detach(array_diff($current, $teams));
                $agent->teams()->attach(array_fill_keys(
                    array_diff($teams, $current),
                    ['tenant_id' => $agent->tenant_id, 'joined_at' => $this->clock->now()],
                ));
            }

            Audit::record($before === [] ? 'agent.created' : 'agent.profile_changed', $agent, [
                'before' => $before,
                'after' => Arr::only($data, ['user_id', 'capacity', 'availability', 'skills', 'team_ids']),
            ]);

            return $this->loadForResource($agent);
        });
    }

    private function loadForResource(AgentProfile $agent): AgentProfile
    {
        return $agent->load(['user', 'skills', 'teams']);
    }
}
