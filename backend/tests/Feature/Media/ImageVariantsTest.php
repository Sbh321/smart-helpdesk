<?php

declare(strict_types=1);

use App\Modules\Media\Jobs\GenerateImageVariants;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/MediaTestSupport.php';

beforeEach(function (): void {
    useMediaTestDisk();
    $this->acme = createTenant('acme');
    $this->user = actingAsRole($this->acme, 'agent');
});

afterEach(fn () => cleanMediaTestDisk());

/** A ready image whose original really is in storage, without running the job. */
function readyImage(Tenant $tenant, int $width, int $height): MediaItem
{
    $item = MediaItem::factory()->forTenant($tenant)->ready()->image($width, $height)->create();
    putMediaObject($tenant, $item->storage_key, pngBytes($width, $height));

    return $item;
}

function runVariants(Tenant $tenant, MediaItem $item): MediaItem
{
    $tenant->run(fn () => app()->call([new GenerateImageVariants($item->id), 'handle']));

    return $tenant->run(fn () => MediaItem::query()->findOrFail($item->id));
}

it('is dispatched on the media queue when an image completes, and not for other files', function (): void {
    Queue::fake();

    $image = stageUpload($this->acme, 'shot.png', pngBytes(), 'image/png');
    $text = stageUpload($this->acme, 'notes.txt', "plain\n", 'text/plain');
    $this->postJson("/v1/media/{$image}/complete")->assertOk();
    $this->postJson("/v1/media/{$text}/complete")->assertOk();

    Queue::assertPushedOn('media', GenerateImageVariants::class, fn (GenerateImageVariants $job): bool => $job->mediaItemId === $image);
    Queue::assertPushed(GenerateImageVariants::class, 1);
    expect((new GenerateImageVariants($image))->queue)->toBe(GenerateImageVariants::QUEUE);
});

it('writes a WebP preview and thumb that keep the aspect ratio', function (): void {
    $item = readyImage($this->acme, 2400, 1200);

    $item = runVariants($this->acme, $item);

    // jsonb does not keep key order.
    expect($item->variants)->toEqual([
        'preview' => ['key' => MediaKeys::variant($item->id, 'preview'), 'width' => 1200, 'height' => 600],
        'thumb' => ['key' => MediaKeys::variant($item->id, 'thumb'), 'width' => 240, 'height' => 120],
    ])->and($item->state)->toBe('ready');

    foreach (['preview' => [1200, 600], 'thumb' => [240, 120]] as $name => [$width, $height]) {
        $size = getimagesize(mediaObjectPath($this->acme, MediaKeys::variant($item->id, $name)));
        expect([$size[0], $size[1], $size['mime']])->toBe([$width, $height, 'image/webp']);
    }

    $this->getJson("/v1/media/{$item->id}")->assertOk()
        ->assertJsonPath('data.variants.thumb', ['width' => 240, 'height' => 120])
        ->assertJsonPath('data.variants.preview', ['width' => 1200, 'height' => 600])
        ->assertJsonPath('data.variants_skipped', null);
});

it('never enlarges a small image', function (): void {
    $item = runVariants($this->acme, readyImage($this->acme, 100, 50));

    expect($item->variants['preview'])->toMatchArray(['width' => 100, 'height' => 50])
        ->and($item->variants['thumb'])->toMatchArray(['width' => 100, 'height' => 50]);
});

it('skips an image over the pixel cap before decoding it', function (): void {
    config(['helpdesk.media.variant_max_pixels' => 10_000]);
    $item = readyImage($this->acme, 200, 51);   // 10 200 pixels

    $item = runVariants($this->acme, $item);

    expect($item->variants)->toBe(['variants_skipped' => 'pixel_limit'])
        ->and($item->state)->toBe('ready')
        ->and(glob(dirname(mediaObjectPath($this->acme, $item->storage_key)).'/*.webp'))->toBe([]);
    $this->getJson("/v1/media/{$item->id}")->assertOk()
        ->assertJsonPath('data.variants_skipped', 'pixel_limit')
        ->assertJsonPath('data.variants', ['thumb' => null, 'preview' => null]);
    $this->get("/v1/media/{$item->id}/download")->assertRedirect();
});

it('generates variants for an image exactly at the cap', function (): void {
    config(['helpdesk.media.variant_max_pixels' => 10_000]);

    $item = runVariants($this->acme, readyImage($this->acme, 100, 100));

    expect($item->variants)->toHaveKeys(['thumb', 'preview']);
});

it('does nothing for files that are not ready images, or that already have variants', function (): void {
    $text = mediaItemIn($this->acme, 'ready');
    $pending = MediaItem::factory()->forTenant($this->acme)->image()->create();
    $done = MediaItem::factory()->forTenant($this->acme)->ready()->image()->create(['variants' => ['variants_skipped' => 'failed']]);

    expect(runVariants($this->acme, $text)->variants)->toBe([])
        ->and(runVariants($this->acme, $pending)->variants)->toBe([])
        ->and(runVariants($this->acme, $done)->variants)->toBe(['variants_skipped' => 'failed']);

    // An item purged before the job ran is not an error.
    $this->acme->run(fn () => app()->call([new GenerateImageVariants('01920000-0000-7000-8000-000000000000'), 'handle']));
});

it('throws when the original is missing, and marks the item skipped once the job has failed', function (): void {
    $item = MediaItem::factory()->forTenant($this->acme)->ready()->image()->create();

    expect(fn () => runVariants($this->acme, $item))->toThrow(RuntimeException::class);

    $this->acme->run(fn () => (new GenerateImageVariants($item->id))->failed(new RuntimeException('gone')));
    $item = $this->acme->run(fn () => MediaItem::query()->findOrFail($item->id));
    expect($item->variants)->toBe(['variants_skipped' => 'failed'])->and($item->state)->toBe('ready');
});
