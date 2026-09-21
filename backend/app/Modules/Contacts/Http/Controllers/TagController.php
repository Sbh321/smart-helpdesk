<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Http\Controllers;

use App\Modules\Contacts\Http\Requests\TagRequest;
use App\Modules\Contacts\Http\Resources\TagResource;
use App\Modules\Contacts\Models\Tag;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;

#[Group('Tags')]
final class TagController
{
    /**
     * List tags, optionally matching a search term (for the tag picker).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:40']]);
        $search = trim((string) ($validated['search'] ?? ''));

        $tags = Tag::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(100)
            ->get();

        return TagResource::collection($tags);
    }

    /**
     * Create a tag.
     */
    #[Response(status: 201, type: TagResource::class)]
    public function store(TagRequest $request): JsonResponse
    {
        $name = trim((string) $request->validated('name'));

        $tag = Tag::query()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => $request->validated('color'),
        ]);

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    /**
     * Delete a tag and remove it from everything it labels.
     */
    public function destroy(Tag $tag): HttpResponse
    {
        $tag->delete();

        return response()->noContent();
    }
}
