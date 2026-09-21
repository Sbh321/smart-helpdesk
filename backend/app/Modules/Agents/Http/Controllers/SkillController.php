<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Controllers;

use App\Modules\Agents\Contracts\DirectoryUsage;
use App\Modules\Agents\Domain\Exceptions\RecordInUse;
use App\Modules\Agents\Http\Requests\IndexDirectoryRequest;
use App\Modules\Agents\Http\Requests\SaveSkillRequest;
use App\Modules\Agents\Http\Resources\SkillResource;
use App\Modules\Agents\Models\Skill;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Agents')]
final class SkillController
{
    public function index(IndexDirectoryRequest $request): AnonymousResourceCollection
    {
        $query = Skill::query();
        if (($search = $request->search()) !== null) {
            $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%');
        }
        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return SkillResource::collection($query->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    #[ScrambleResponse(status: 201, type: SkillResource::class)]
    public function store(SaveSkillRequest $request): JsonResponse
    {
        $skill = Skill::query()->create($request->validated());

        return (new SkillResource($skill))->response()->setStatusCode(201);
    }

    public function update(SaveSkillRequest $request, Skill $skill): SkillResource
    {
        $skill->update($request->validated());

        return new SkillResource($skill->refresh());
    }

    public function destroy(Skill $skill, DirectoryUsage $usage): Response
    {
        $references = ['agents' => $skill->agents()->count(), ...$usage->skillReferences($skill)];
        if (array_sum($references) > 0) {
            throw RecordInUse::for('skill', $skill->id, $references);
        }
        $skill->delete();

        return response()->noContent();
    }
}
