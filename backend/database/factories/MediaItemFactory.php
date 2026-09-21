<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use Database\Factories\Concerns\ForTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaItem> */
final class MediaItemFactory extends Factory
{
    use ForTenant;

    protected $model = MediaItem::class;

    public function definition(): array
    {
        return [
            'folder_id' => null,
            'name' => fake()->word().'.txt',
            'storage_key' => fn (): string => MediaKeys::original((string) Str::uuid7(), 'txt'),
            'mime_type' => 'text/plain',
            'size_bytes' => 12,
            'width' => null,
            'height' => null,
            'checksum_sha256' => null,
            'variants' => [],
            'source' => 'upload',
            'state' => 'pending',
            'uploaded_by_user_id' => null,
            'trashed_at' => null,
            'completed_at' => null,
        ];
    }

    public function configure(): static
    {
        // The object key is built from the row's own id, as RegisterUpload does.
        return $this->afterMaking(function (MediaItem $item): void {
            $item->id ??= (string) Str::uuid7();
            $item->storage_key = MediaKeys::original($item->id, pathinfo($item->storage_key, PATHINFO_EXTENSION) ?: 'txt');
        });
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'state' => 'ready',
            'checksum_sha256' => hash('sha256', 'hello world!'),
            'completed_at' => now(),
        ]);
    }

    public function trashed(): static
    {
        return $this->ready()->state(fn (): array => ['state' => 'trashed', 'trashed_at' => now()]);
    }

    public function image(int $width = 640, int $height = 480): static
    {
        return $this->state(fn (): array => [
            'name' => fake()->word().'.png',
            'storage_key' => MediaKeys::original((string) Str::uuid7(), 'png'),
            'mime_type' => 'image/png',
            'width' => $width,
            'height' => $height,
        ]);
    }
}
