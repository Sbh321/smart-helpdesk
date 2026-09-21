<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Controllers;

use App\Models\User;
use App\Modules\Media\Actions\CompleteUpload;
use App\Modules\Media\Actions\RegisterUpload;
use App\Modules\Media\Http\Requests\UploadIntentRequest;
use App\Modules\Media\Http\Resources\MediaItemResource;
use App\Modules\Media\Http\Resources\UploadIntentResource;
use App\Modules\Media\Models\MediaItem;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Media')]
final class MediaUploadController
{
    #[ScrambleResponse(status: 201, type: UploadIntentResource::class)]
    public function intent(UploadIntentRequest $request, RegisterUpload $register): JsonResponse
    {
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        return (new UploadIntentResource($register(
            (string) $data['filename'], (int) $data['size'], (string) ($data['mime'] ?? ''), $actor, (string) ($data['purpose'] ?? 'attachment'),
        )))->response()->setStatusCode(201);
    }

    public function complete(Request $request, MediaItem $media, CompleteUpload $complete): MediaItemResource
    {
        // Only the uploader (or a media manager) may finish somebody's upload.
        if ($media->uploaded_by_user_id !== $request->user()?->id && $request->user()?->can('media.manage') !== true) {
            throw new AuthorizationException;
        }

        return new MediaItemResource($complete($media));
    }
}
