<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Enums\PlanKind;
use App\Modules\Billing\Http\Requests\PlanRequest;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Support\BillingException;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Plans for platform super admins (ADR-0025 §1). Plans are archived (`is_active: false`), never deleted. */
#[Group('Platform: billing')]
final class PlatformPlanController
{
    /** List plans. */
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->withCount('subscriptions')->ordered()->get());
    }

    /** Create a plan. */
    #[Response(status: 201, type: PlanResource::class)]
    public function store(PlanRequest $request): JsonResponse
    {
        $plan = new Plan(['currency' => 'NPR', 'price_minor' => 0, 'is_active' => true, 'sort_order' => 0]);
        $this->save($plan, $request->validated());
        Audit::record('plan.created', $plan, $request->validated(), tenantId: null);

        return (new PlanResource($plan->loadCount('subscriptions')))->response()->setStatusCode(201);
    }

    /** Update a plan. Its code and kind cannot change. */
    public function update(PlanRequest $request, Plan $plan): PlanResource
    {
        $before = array_intersect_key($plan->getOriginal(), $request->validated());
        $this->save($plan, $request->validated());
        Audit::record('plan.updated', $plan, ['before' => $before, 'after' => $request->validated()], tenantId: null);

        return new PlanResource($plan->loadCount('subscriptions'));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function save(Plan $plan, array $values): void
    {
        $plan->fill($values);
        $activeTrial = $plan->kind === PlanKind::Trial && $plan->is_active;
        if ($activeTrial && Plan::query()->where('kind', PlanKind::Trial)->where('is_active', true)->whereKeyNot($plan->getKey())->exists()) {
            throw BillingException::field('is_active', 'Only one trial plan can be active: new workspaces start on it. Archive the other trial plan first.');
        }
        $plan->save();
    }
}
