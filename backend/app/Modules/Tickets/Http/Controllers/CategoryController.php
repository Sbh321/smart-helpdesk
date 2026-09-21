<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Modules\Tickets\Http\Requests\SaveCategoryRequest;
use App\Modules\Tickets\Http\Resources\CategoryResource;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

#[Group('Tickets')]
final class CategoryController
{
    /**
     * List the workspace's active categories, in display order.
     */
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->active()->with(['defaultTeam', 'skills'])->orderBy('sort_order')->orderBy('name')->get(),
        );
    }

    #[Response(status: 201, type: CategoryResource::class)]
    public function store(SaveCategoryRequest $request): JsonResponse
    {
        $category = $this->save($request->validated(), new Category);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(SaveCategoryRequest $request, Category $category): CategoryResource
    {
        return new CategoryResource($this->save($request->validated(), $category));
    }

    public function destroy(Category $category): HttpResponse
    {
        if (Ticket::query()->where('category_id', $category->id)->exists()) {
            abort(409);
        }

        $category->delete();

        return response()->noContent();
    }

    /** @param array<string, mixed> $data */
    private function save(array $data, Category $category): Category
    {
        return DB::transaction(function () use ($data, $category): Category {
            $category->fill(Arr::except($data, ['skill_ids']))->save();
            if (array_key_exists('skill_ids', $data)) {
                /** @var list<string> $skillIds */
                $skillIds = $data['skill_ids'];
                $category->skills()->syncWithPivotValues($skillIds, ['tenant_id' => $category->tenant_id]);
            }

            return $category->load(['defaultTeam', 'skills']);
        });
    }
}
