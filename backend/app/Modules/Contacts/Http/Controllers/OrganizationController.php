<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Controllers;

use App\Modules\Contacts\Http\Requests\IndexOrganizationsRequest;
use App\Modules\Contacts\Http\Requests\OrganizationRequest;
use App\Modules\Contacts\Http\Resources\OrganizationResource;
use App\Modules\Contacts\Models\Organization;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

#[Group('Contacts')]
final class OrganizationController
{
    /**
     * List organisations.
     */
    #[QueryParameter('filter[tier]', 'Tiers, comma separated: standard, premium, enterprise.', type: 'string')]
    #[QueryParameter('filter[tag]', 'Tag slugs, comma separated.', type: 'string')]
    public function index(IndexOrganizationsRequest $request): AnonymousResourceCollection
    {
        $query = Organization::query()->with('tags')->withCount('contacts');

        $tiers = $request->filterValues('tier');
        $query->when($tiers !== [], fn (Builder $q) => $q->whereIn('tier', $tiers));

        $tags = $request->filterValues('tag');
        $query->when($tags !== [], fn (Builder $q) => $q->whereHas('tags', fn (Builder $t) => $t->whereIn('slug', $tags)));

        $search = $request->search();
        $query->when($search !== null, fn (Builder $q) => $q->where('name', 'ilike', '%'.addcslashes((string) $search, '%_\\').'%'));

        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return OrganizationResource::collection($query->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    /**
     * Create an organisation.
     */
    #[Response(status: 201, type: OrganizationResource::class)]
    public function store(OrganizationRequest $request): JsonResponse
    {
        $organization = $this->save($request->validated(), new Organization);

        return (new OrganizationResource($organization))->response()->setStatusCode(201);
    }

    /**
     * Show an organisation.
     */
    public function show(Organization $organization): OrganizationResource
    {
        return new OrganizationResource($organization->load('tags')->loadCount('contacts'));
    }

    /**
     * Update an organisation. Changing the tier affects the priority of new tickets.
     */
    public function update(OrganizationRequest $request, Organization $organization): OrganizationResource
    {
        return new OrganizationResource($this->save($request->validated(), $organization));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, Organization $organization): Organization
    {
        return DB::transaction(function () use ($data, $organization): Organization {
            $organization->fill(Arr::except($data, ['tags']))->save();

            if (array_key_exists('tags', $data)) {
                /** @var list<string> $tags */
                $tags = $data['tags'];
                $organization->syncTagNames($tags);
            }

            return $organization->load('tags')->loadCount('contacts');
        });
    }
}
