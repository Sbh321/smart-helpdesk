<?php

declare(strict_types=1);

namespace App\Modules\Sla\Http\Controllers;

use App\Modules\Audit\Audit;
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
    /** List SLA policies. */
    public function index(): AnonymousResourceCollection
    {
        return SlaPolicyResource::collection(SlaPolicy::query()->with('targets')->orderBy('name')->get());
    }

    /** Create an SLA policy. */
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
            Audit::record('sla_policy.created', $policy, self::audited($policy->refresh()));

            return $policy;
        });

        // refresh(): the response carries the column defaults the insert did not set.
        return (new SlaPolicyResource($policy->refresh()->load('targets')))->response()->setStatusCode(201);
    }

    /** Get an SLA policy. */
    public function show(SlaPolicy $policy): SlaPolicyResource
    {
        return new SlaPolicyResource($policy->load('targets'));
    }

    /** Update an SLA policy. */
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
            $before = self::audited($policy);
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
            $after = self::audited($policy);
            $changes = [];
            foreach ($after as $field => $value) {
                if ($field !== 'version' && $value !== ($before[$field] ?? null)) {
                    $changes[$field] = ['old' => $before[$field] ?? null, 'new' => $value];
                }
            }
            Audit::record('sla_policy.updated', $policy, [...$changes, 'version' => $after['version']]);
        });

        return new SlaPolicyResource($policy->refresh()->load('targets'));
    }

    /** Delete an SLA policy. */
    public function destroy(SlaPolicy $policy): Response
    {
        $usedBy = array_keys(array_filter([
            'default_policy' => $policy->is_default,
            'ticket_sla_timers' => TicketSlaTimer::query()->where('policy_id', $policy->id)->exists(),
        ]));
        if ($usedBy !== []) {
            throw RecordInUse::because('This SLA policy is still in use and cannot be deleted.', 'sla_policy', $usedBy);
        }
        $snapshot = self::audited($policy);
        $policy->delete();
        Audit::record('sla_policy.deleted', $policy, $snapshot);

        return response()->noContent();
    }

    /**
     * The policy as its audit entries record it: settings and the minutes per priority.
     *
     * @return array<string, mixed>
     */
    private static function audited(SlaPolicy $policy): array
    {
        $targets = [];
        foreach ($policy->targets()->orderBy('priority_level')->get() as $target) {
            $targets[$target->priority_level->value] = [
                'first_response_minutes' => $target->first_response_minutes,
                'resolution_minutes' => $target->resolution_minutes,
            ];
        }

        return [
            'name' => $policy->name,
            'is_default' => $policy->is_default,
            'applies_to_tier' => $policy->applies_to_tier?->value,
            'warning_fraction' => (string) $policy->warning_fraction,
            'calendar_id' => $policy->calendar_id,
            'targets' => $targets,
            'version' => $policy->version,
        ];
    }
}
