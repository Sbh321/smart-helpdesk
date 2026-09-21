<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Modules\Media\Exceptions\MediaInUse;
use App\Modules\Media\Exceptions\MediaStateConflict;
use App\Modules\Media\Http\Requests\SaveMediaFolderRequest;
use App\Modules\Media\Http\Requests\UpdateMediaFolderRequest;
use App\Modules\Media\Http\Resources\MediaFolderResource;
use App\Modules\Media\Models\MediaFolder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Group('Media')]
final class MediaFolderController
{
    private const MAX_DEPTH = 5;

    /** List media folders. */
    public function index(): AnonymousResourceCollection
    {
        return MediaFolderResource::collection(MediaFolder::query()->orderBy('name')->get());
    }

    /** Create a media folder. */
    #[ScrambleResponse(status: 201, type: MediaFolderResource::class)]
    public function store(SaveMediaFolderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $parent = isset($data['parent_id']) ? MediaFolder::query()->findOrFail($data['parent_id']) : null;
        if ($this->depth($parent) >= self::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_id' => 'Folders can be nested at most five levels.']);
        }

        $folder = $this->saveWithUniqueName(fn (): MediaFolder => MediaFolder::query()->create([
            'name' => trim($data['name']),
            'parent_id' => $parent?->id,
            'system_key' => null,
        ]));

        return (new MediaFolderResource($folder))->response()->setStatusCode(201);
    }

    /** Rename or move a media folder. */
    public function update(UpdateMediaFolderRequest $request, MediaFolder $folder): MediaFolderResource
    {
        if ($folder->system_key !== null) {
            throw MediaStateConflict::systemFolder();
        }
        $data = $request->validated();
        $parent = array_key_exists('parent_id', $data)
            ? ($data['parent_id'] === null ? null : MediaFolder::query()->findOrFail($data['parent_id']))
            : $folder->parent;
        if ($parent?->id === $folder->id || $this->isDescendantOf($parent, $folder->id)) {
            throw ValidationException::withMessages(['parent_id' => 'A folder cannot be moved into itself or a child.']);
        }
        if ($this->depth($parent) + $this->height($folder) > self::MAX_DEPTH) {
            throw ValidationException::withMessages(['parent_id' => 'Folders can be nested at most five levels.']);
        }

        $name = trim($data['name'] ?? $folder->name);
        $this->saveWithUniqueName(function () use ($folder, $name, $parent): MediaFolder {
            $folder->forceFill(['name' => $name, 'parent_id' => $parent?->id])->save();

            return $folder;
        });

        return new MediaFolderResource($folder);
    }

    /** Delete a media folder. */
    public function destroy(MediaFolder $folder): Response
    {
        if ($folder->system_key !== null) {
            throw MediaStateConflict::systemFolder();
        }

        try {
            // The savepoint keeps an outer transaction usable; the RESTRICT foreign key on
            // child folders decides races. Items would only be un-filed (SET NULL), so they are checked.
            DB::transaction(function () use ($folder): void {
                MediaFolder::query()->whereKey($folder->id)->lockForUpdate()->first();
                if ($folder->children()->exists() || $folder->items()->exists()) {
                    throw MediaInUse::folder();
                }
                $folder->delete();
            });
        } catch (QueryException $exception) {
            throw $exception->getCode() === '23503' ? MediaInUse::folder() : $exception;
        }

        return response()->noContent();
    }

    /**
     * The sibling-name unique index is the judge, so two simultaneous requests cannot both win;
     * the loser gets the same 422 as a sequential duplicate instead of a 500.
     *
     * @param  callable(): MediaFolder  $save
     */
    private function saveWithUniqueName(callable $save): MediaFolder
    {
        try {
            return DB::transaction(fn (): MediaFolder => $save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A folder with this name already exists here.']);
        }
    }

    private function depth(?MediaFolder $folder): int
    {
        $depth = 0;
        while ($folder !== null) {
            $depth++;
            $folder = $folder->parent;
        }

        return $depth;
    }

    private function isDescendantOf(?MediaFolder $folder, string $ancestorId): bool
    {
        while ($folder !== null) {
            if ($folder->id === $ancestorId) {
                return true;
            }
            $folder = $folder->parent;
        }

        return false;
    }

    private function height(MediaFolder $folder): int
    {
        $height = 1;
        foreach ($folder->children as $child) {
            $height = max($height, 1 + $this->height($child));
        }

        return $height;
    }
}
