<?php

declare(strict_types=1);

use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/MediaTestSupport.php';

beforeEach(function (): void {
    useMediaTestDisk();
    $this->clock = new FrozenClock(CarbonImmutable::parse('2026-09-20 10:00:00'));
    app()->instance(Clock::class, $this->clock);
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'manager');
});

afterEach(fn () => cleanMediaTestDisk());

function storageUsed(Tenant $tenant): int
{
    return (int) DB::table('tenant_counters')->where('tenant_id', $tenant->getKey())->value('storage_used_bytes');
}

function itemExists(string $id): bool
{
    return MediaItem::query()->withoutTenancy()->whereKey($id)->exists();
}

describe('trash and restore', function (): void {
    it('trashes a ready item, hides it from the library and keeps its bytes counted', function (): void {
        $item = uploadReady($this->acme, 'notes.txt', "twelve bytes\n");

        $this->postJson("/v1/media/{$item->id}/trash")->assertOk()
            ->assertJsonPath('data.state', 'trashed')
            ->assertJsonPath('data.trashed_at', '2026-09-20T10:00:00.000000Z');

        $this->getJson('/v1/media')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/media?filter[trashed]=true')->assertOk()->assertJsonPath('data.0.id', $item->id);
        $this->getJson("/v1/media/{$item->id}/download")->assertNotFound();
        expect(storageUsed($this->acme))->toBe(13)
            ->and(mediaObjectPath($this->acme, $item->storage_key))->toBeFile();
    });

    it('restores a trashed item', function (): void {
        $item = mediaItemIn($this->acme, 'trashed');

        $this->postJson("/v1/media/{$item->id}/restore")->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.trashed_at', null);
        $this->getJson('/v1/media')->assertOk()->assertJsonPath('data.0.id', $item->id);
    });

    it('answers 409 when the item is not in the state the action needs', function (string $action, string $state): void {
        $item = mediaItemIn($this->acme, $state);

        $this->postJson("/v1/media/{$item->id}/{$action}")
            ->assertStatus(409)->assertJsonPath('code', 'conflict')
            ->assertJsonPath('meta.reason', 'media_state')->assertJsonPath('meta.state', $state);
    })->with([
        ['trash', 'trashed'], ['trash', 'pending'], ['trash', 'failed'],
        ['restore', 'ready'], ['restore', 'pending'],
    ]);

    it('trashes an item that is still linked: links survive and block only the purge', function (): void {
        $item = mediaItemIn($this->acme);
        Mediable::factory()->forTenant($this->acme)->create(['media_item_id' => $item->id]);

        $this->postJson("/v1/media/{$item->id}/trash")->assertOk()->assertJsonPath('data.used_in_count', 1);
        $this->deleteJson("/v1/media/{$item->id}")
            ->assertStatus(409)->assertJsonPath('code', 'in_use')->assertJsonPath('meta.links', 1);

        expect(itemExists($item->id))->toBeTrue();
    });
});

describe('purge', function (): void {
    it('removes the row, the tags, the original, the variants and the used bytes', function (): void {
        $bytes = pngBytes(64, 48);
        $item = uploadReady($this->acme, 'shot.png', $bytes, 'image/png');   // the sync queue also writes the variants
        $keep = uploadReady($this->acme, 'keep.txt', "stays\n");
        $this->patchJson("/v1/media/{$item->id}", ['tags' => ['Screens']])->assertOk();
        $directory = dirname(mediaObjectPath($this->acme, $item->storage_key));
        expect(glob($directory.'/*'))->toHaveCount(3)
            ->and(storageUsed($this->acme))->toBe(strlen($bytes) + 6);

        $this->postJson("/v1/media/{$item->id}/trash")->assertOk();
        $this->deleteJson("/v1/media/{$item->id}")->assertNoContent();

        expect(itemExists($item->id))->toBeFalse()
            ->and(glob($directory.'/*'))->toBe([])
            ->and(DB::table('taggables')->where('taggable_id', $item->id)->count())->toBe(0)
            ->and(storageUsed($this->acme))->toBe(6)
            ->and(mediaObjectPath($this->acme, $keep->storage_key))->toBeFile();
        $this->getJson('/v1/media/usage')->assertJsonPath('data.used_bytes', 6);
        $this->deleteJson("/v1/media/{$item->id}")->assertNotFound();
    });

    it('purges only from the trash', function (string $state): void {
        $item = mediaItemIn($this->acme, $state);

        $this->deleteJson("/v1/media/{$item->id}")
            ->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('meta.expected', ['trashed']);

        expect(itemExists($item->id))->toBeTrue();
    })->with(['ready', 'pending']);

    it('never lets the used bytes go below zero', function (): void {
        $item = mediaItemIn($this->acme, 'trashed', ['size_bytes' => 500]);
        DB::table('tenant_counters')->upsert(['tenant_id' => $this->acme->getKey(), 'storage_used_bytes' => 100], 'tenant_id');

        $this->deleteJson("/v1/media/{$item->id}")->assertNoContent();

        expect(storageUsed($this->acme))->toBe(0);
    });
});

describe('usage', function (): void {
    it('reports used, reserved and quota bytes of this workspace only', function (): void {
        DB::table('tenants')->where('id', $this->acme->getKey())->update(['storage_quota_bytes' => 10_000]);
        uploadReady($this->acme, 'a.txt', str_repeat('a', 300));
        $trashed = uploadReady($this->acme, 'b.txt', str_repeat('b', 200));
        $this->postJson("/v1/media/{$trashed->id}/trash")->assertOk();
        requestIntent('c.txt', 'x', 'text/plain', 150)->assertCreated();
        mediaItemIn($this->acme, 'failed', ['size_bytes' => 999]);
        mediaItemIn($this->globex, 'pending', ['size_bytes' => 4000]);
        DB::table('tenant_counters')->upsert(['tenant_id' => $this->globex->getKey(), 'storage_used_bytes' => 7000], 'tenant_id');

        // Trash still occupies storage; failed uploads reserve nothing.
        $this->getJson('/v1/media/usage')->assertOk()
            ->assertExactJson(['data' => ['used_bytes' => 500, 'quota_bytes' => 10_000, 'pending_bytes' => 150]]);
    });

    it('defaults to a 5 GiB quota and zero usage', function (): void {
        $this->getJson('/v1/media/usage')->assertOk()
            ->assertExactJson(['data' => ['used_bytes' => 0, 'quota_bytes' => 5 * 1024 ** 3, 'pending_bytes' => 0]]);
    });

    it('frees quota when trash is purged, not when an item is trashed', function (): void {
        DB::table('tenants')->where('id', $this->acme->getKey())->update(['storage_quota_bytes' => 1000]);
        $item = uploadReady($this->acme, 'big.txt', str_repeat('a', 800));

        $this->postJson("/v1/media/{$item->id}/trash")->assertOk();
        requestIntent('next.txt', 'x', 'text/plain', 300)->assertStatus(422)
            ->assertJsonPath('code', 'quota_exceeded')
            ->assertJsonPath('status', 422);

        $this->deleteJson("/v1/media/{$item->id}")->assertNoContent();
        requestIntent('next.txt', 'x', 'text/plain', 300)->assertCreated();
    });
});

describe('media:cleanup', function (): void {
    it('purges stale pending and failed uploads, staged orphans and old unlinked trash', function (): void {
        $stalePending = mediaItemIn($this->acme, 'pending', ['created_at' => '2026-09-20 08:30:00']);
        $staleFailed = mediaItemIn($this->acme, 'failed', ['created_at' => '2026-09-20 08:30:00']);
        $freshPending = mediaItemIn($this->acme, 'pending', ['created_at' => '2026-09-20 09:30:00']);
        $oldTrash = mediaItemIn($this->acme, 'trashed', ['trashed_at' => '2026-08-20 09:00:00', 'size_bytes' => 40]);
        $linkedTrash = mediaItemIn($this->acme, 'trashed', ['trashed_at' => '2026-08-20 09:00:00']);
        Mediable::factory()->forTenant($this->acme)->create(['media_item_id' => $linkedTrash->id]);
        $recentTrash = mediaItemIn($this->acme, 'trashed', ['trashed_at' => '2026-09-01 09:00:00']);
        $ready = mediaItemIn($this->acme, 'ready', ['created_at' => '2026-01-01 09:00:00']);
        DB::table('tenant_counters')->upsert(['tenant_id' => $this->acme->getKey(), 'storage_used_bytes' => 100], 'tenant_id');

        putMediaObject($this->acme, $oldTrash->storage_key, 'old trash');
        putMediaObject($this->acme, MediaKeys::staging($stalePending->id, 'txt'), 'abandoned');
        putMediaObject($this->acme, MediaKeys::staging($freshPending->id, 'txt'), 'in flight');
        touch(mediaObjectPath($this->acme, MediaKeys::staging($stalePending->id, 'txt')), $this->clock->now()->subHours(2)->getTimestamp());
        touch(mediaObjectPath($this->acme, MediaKeys::staging($freshPending->id, 'txt')), $this->clock->now()->subMinutes(5)->getTimestamp());

        $this->artisan('media:cleanup')->assertSuccessful();

        expect(array_map(itemExists(...), [$stalePending->id, $staleFailed->id, $oldTrash->id]))->toBe([false, false, false])
            ->and(array_map(itemExists(...), [$freshPending->id, $linkedTrash->id, $recentTrash->id, $ready->id]))->toBe([true, true, true, true])
            ->and(file_exists(mediaObjectPath($this->acme, $oldTrash->storage_key)))->toBeFalse()
            ->and(file_exists(mediaObjectPath($this->acme, MediaKeys::staging($stalePending->id, 'txt'))))->toBeFalse()
            ->and(mediaObjectPath($this->acme, MediaKeys::staging($freshPending->id, 'txt')))->toBeFile()
            // Only the trashed item counted towards the quota; pending and failed ones never did.
            ->and(storageUsed($this->acme))->toBe(60);
    });
});
