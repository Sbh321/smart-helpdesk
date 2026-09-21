<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Controllers;

use App\Modules\Sla\Exceptions\RecordInUse;
use App\Modules\Sla\Http\Requests\SavePolicyRequest;
use App\Modules\Sla\Http\Resources\SlaPolicyResource;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Sla\Models\TicketSlaTimer;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

#[Group('SLA')]
final class SlaPolicyController
{
    public function index(): AnonymousResourceCollection
    {
        return SlaPolicyResource::collection(SlaPolicy::query()->with('targets')->orderBy('name')->get());
    }

    #[ScrambleResponse(status: 201, type: SlaPolicyResource::class)]
    public function store(SavePolicyRequest $request): JsonResponse
    {
        $policy = DB::transaction(function () use ($request): SlaPolicy {
            $data = $request->validated();
            $targets = $data['targets'];
            unset($data['targets']);
            if ($data['is_default'] ?? false) {
                $data['applies_to_tier'] = null;
                SlaPolicy::query()->where('is_default', true)->update(['is_default' => false]);
            }
            $policy = SlaPolicy::query()->create($data);
            foreach ($targets as $minutes) {
                $policy->targets()->create([
                    'priority_level' => $minutes['priority_level'],
                    'first_response_minutes' => $minutes['first_response_minutes'],
                    'resolution_minutes' => $minutes['resolution_minutes'],
                ]);
            }

            return $policy;
        });

        // refresh(): the response carries the column defaults the insert did not set.
        return (new SlaPolicyResource($policy->refresh()->load('targets')))->response()->setStatusCode(201);
    }

    public function show(SlaPolicy $policy): SlaPolicyResource
    {
        return new SlaPolicyResource($policy->load('targets'));
    }

    public function update(SavePolicyRequest $request, SlaPolicy $policy): SlaPolicyResource
    {
        DB::transaction(function () use ($request, $policy): void {
            $data = $request->validated();
            $targets = $data['targets'] ?? null;
            unset($data['targets']);
            if ($policy->is_default && array_key_exists('is_default', $data) && ! $data['is_default']) {
                throw RecordInUse::because(
                    'The default SLA policy stays the default until another policy is made the default.',
                    'sla_policy',
                    ['default_policy'],
                );
            }
            if ($data['is_default'] ?? false) {
                $data['applies_to_tier'] = null;
                SlaPolicy::query()->where('is_default', true)->whereKeyNot($policy->id)->update(['is_default' => false]);
            }
            $policy->fill($data);
            $policy->version++;
            $policy->save();
            if ($targets !== null) {
                foreach ($targets as $minutes) {
                    $policy->targets()->updateOrCreate(['priority_level' => $minutes['priority_level']], [
                        'first_response_minutes' => $minutes['first_response_minutes'],
                        'resolution_minutes' => $minutes['resolution_minutes'],
                    ]);
                }
            }
        });

        return new SlaPolicyResource($policy->refresh()->load('targets'));
    }

    public function destroy(SlaPolicy $policy): Response
    {
        $usedBy = array_keys(array_filter([
            'default_policy' => $policy->is_default,
            'ticket_sla_timers' => TicketSlaTimer::query()->where('policy_id', $policy->id)->exists(),
        ]));
        if ($usedBy !== []) {
            throw RecordInUse::because('This SLA policy is still in use and cannot be deleted.', 'sla_policy', $usedBy);
        }
        $policy->delete();

        return response()->noContent();
    }
}
