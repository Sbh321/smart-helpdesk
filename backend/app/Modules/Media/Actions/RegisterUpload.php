<?php

declare(strict_types=1);

namespace App\Modules\Media\Actions;

use App\Models\User;
use App\Modules\Media\Domain\UploadIntent;
use App\Modules\Media\Exceptions\QuotaExceeded;
use App\Modules\Media\Exceptions\StorageUnavailable;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\MediaFilename;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Media\Support\MediaStorage;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Upload intent: reserves quota with a `pending` item and signs a PUT to a STAGING key. The
 * object only reaches its media key after CompleteUpload verified it, so replaying the signed
 * URL later can never change a ready item.
 */
final class RegisterUpload
{
    public const URL_TTL_SECONDS = 300;

    public function __construct(
        private readonly MediaStorage $storage,
        private readonly MediaQuota $quota,
        private readonly Clock $clock,
    ) {}

    /** System folders an upload may start in; everything else is moved there later by a media manager. */
    public const array PURPOSE_FOLDERS = [
        'attachment' => ['tickets', 'Tickets'],
        'branding' => ['branding', 'Branding'],
        // A payment receipt for the workspace's subscription (ADR-0025 §4).
        'receipt' => ['billing', 'Billing'],
    ];

    public function __invoke(string $filename, int $size, string $declaredMime, User $actor, string $purpose = 'attachment'): UploadIntent
    {
        $name = MediaFilename::sanitise($filename);
        $extension = AllowedMedia::extensionFor($name);
        if ($extension === null || ! AllowedMedia::acceptsDeclared($extension, $declaredMime)) {
            throw ValidationException::withMessages(['mime' => 'This file type is not allowed.']);
        }
        if ($size < 1 || $size > AllowedMedia::maxBytes()) {
            throw ValidationException::withMessages(['size' => 'The file is larger than the upload limit.']);
        }

        $tenantId = (string) tenant()?->getTenantKey();
        $mime = AllowedMedia::mimeFor($extension);

        [$folderKey, $folderName] = self::PURPOSE_FOLDERS[$purpose] ?? self::PURPOSE_FOLDERS['attachment'];

        $item = DB::transaction(function () use ($tenantId, $name, $size, $mime, $extension, $actor, $folderKey, $folderName): MediaItem {
            $this->quota->lock($tenantId);
            $usage = $this->quota->usage($tenantId);
            if ($usage->used_bytes + $usage->pending_bytes + $size > $usage->quota_bytes) {
                throw new QuotaExceeded;
            }

            $folder = MediaFolder::query()->firstOrCreate(
                ['system_key' => $folderKey],
                ['name' => $folderName, 'parent_id' => null],
            );
            $id = (string) Str::uuid7();
            $now = $this->clock->now();

            $item = new MediaItem;
            $item->id = $id;
            $item->forceFill([
                'folder_id' => $folder->id,
                'name' => $name,
                'storage_key' => MediaKeys::original($id, $extension),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'variants' => [],
                'source' => 'upload',
                'state' => 'pending',
                'uploaded_by_user_id' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $item->save();

            return $item;
        });

        try {
            $signed = $this->storage->uploadUrl(MediaKeys::staging($item->id, $extension), $mime, $size, self::URL_TTL_SECONDS);
        } catch (Throwable $exception) {
            $item->delete();
            throw StorageUnavailable::during('presign', $exception);
        }

        return new UploadIntent($item->id, $signed['url'], $signed['headers']);
    }
}
