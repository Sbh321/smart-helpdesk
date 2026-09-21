<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Tag;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaFilename;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/MediaTestSupport.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'manager');
});

/** @param list<string> $tags */
function taggedItem(Tenant $tenant, array $tags, array $attributes = []): MediaItem
{
    $item = mediaItemIn($tenant, 'ready', $attributes);
    $tenant->run(fn () => $item->syncTagNames($tags));

    return $item;
}

/** @return list<string> */
function listedNames(string $query = ''): array
{
    return test()->getJson('/v1/media'.($query === '' ? '' : '?'.$query))->assertOk()->json('data.*.name');
}

describe('show', function (): void {
    it('shows one item without its storage key', function (): void {
        $item = taggedItem($this->acme, ['Invoices'], ['name' => 'invoice.txt']);

        $this->getJson("/v1/media/{$item->id}")->assertOk()
            ->assertJsonPath('data.id', $item->id)
            ->assertJsonPath('data.name', 'invoice.txt')
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.used_in_count', 0)
            ->assertJsonPath('data.used_in_tickets', [])
            ->assertJsonPath('data.tags', [['name' => 'Invoices', 'slug' => 'invoices']])
            ->assertJsonPath('data.variants', ['thumb' => null, 'preview' => null])
            ->assertJsonMissingPath('data.storage_key')
            ->assertJsonMissingPath('data.tenant_id');
    });

    it('answers 404 for an item of another tenant', function (): void {
        $foreign = mediaItemIn($this->globex);

        $this->getJson("/v1/media/{$foreign->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->patchJson("/v1/media/{$foreign->id}", ['name' => 'stolen.txt'])->assertNotFound();
        $this->postJson("/v1/media/{$foreign->id}/trash")->assertNotFound();
        $this->deleteJson("/v1/media/{$foreign->id}")->assertNotFound();

        expect($this->globex->run(fn (): MediaItem => MediaItem::query()->findOrFail($foreign->id))->state)->toBe('ready');
    });
});

describe('rename', function (): void {
    it('stores the sanitised name, which is what Content-Disposition is built from', function (): void {
        $item = mediaItemIn($this->acme, 'ready', ['name' => 'report.txt']);

        $name = $this->patchJson("/v1/media/{$item->id}", ['name' => "..\\..\\etc/Q3 \"final\";\r\nX-Evil: 1.TXT"])
            ->assertOk()->json('data.name');

        expect($name)->toBe('Q3 _final_X-Evil_ 1.TXT')
            ->and($item->fresh()?->name)->toBe($name)
            ->and(MediaFilename::contentDisposition($name))
            ->toBe("attachment; filename=\"Q3 _final_X-Evil_ 1.TXT\"; filename*=UTF-8''Q3%20_final_X-Evil_%201.TXT");
    });

    it('keeps the type: the extension may change only within the same type', function (): void {
        $jpeg = mediaItemIn($this->acme, 'ready', ['name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        $this->patchJson("/v1/media/{$jpeg->id}", ['name' => 'holiday.jpeg'])->assertOk()->assertJsonPath('data.name', 'holiday.jpeg');
        foreach (['holiday.pdf', 'holiday', 'holiday.jpg.exe'] as $name) {
            $this->patchJson("/v1/media/{$jpeg->id}", ['name' => $name])
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['name']]);
        }

        expect($jpeg->fresh()?->name)->toBe('holiday.jpeg');
    });

    it('rejects a name with nothing usable in it', function (): void {
        $item = mediaItemIn($this->acme);

        $this->patchJson("/v1/media/{$item->id}", ['name' => ' ... '])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
    });
});

describe('move', function (): void {
    it('moves an item into a folder and back out', function (): void {
        $folder = MediaFolder::factory()->forTenant($this->acme)->create();
        $item = mediaItemIn($this->acme, 'ready', ['name' => 'a.txt']);

        $this->patchJson("/v1/media/{$item->id}", ['folder_id' => $folder->id])->assertOk()
            ->assertJsonPath('data.folder_id', $folder->id)->assertJsonPath('data.name', 'a.txt');
        $this->patchJson("/v1/media/{$item->id}", ['folder_id' => null])->assertOk()
            ->assertJsonPath('data.folder_id', null);
    });

    it('answers 404 for a folder of another tenant', function (): void {
        $foreign = MediaFolder::factory()->forTenant($this->globex)->create();
        $item = mediaItemIn($this->acme);

        $this->patchJson("/v1/media/{$item->id}", ['folder_id' => $foreign->id])->assertNotFound();

        expect($item->fresh()?->folder_id)->toBeNull();
    });

    it('answers 409 for an item that is not ready', function (string $state): void {
        $item = mediaItemIn($this->acme, $state);

        $this->patchJson("/v1/media/{$item->id}", ['name' => 'renamed.txt'])
            ->assertStatus(409)->assertJsonPath('code', 'conflict')
            ->assertJsonPath('meta.reason', 'media_state')->assertJsonPath('meta.state', $state);
    })->with(['pending', 'trashed', 'failed']);
});

describe('tags', function (): void {
    it('replaces the tags, reuses existing ones and removes them with an empty list', function (): void {
        $item = taggedItem($this->acme, ['Invoices']);

        $this->patchJson("/v1/media/{$item->id}", ['tags' => ['invoices', 'Q3 Reports']])->assertOk()
            ->assertJsonPath('data.tags', [['name' => 'Invoices', 'slug' => 'invoices'], ['name' => 'Q3 Reports', 'slug' => 'q3-reports']]);
        $this->patchJson("/v1/media/{$item->id}", ['name' => 'kept.txt'])->assertOk()
            ->assertJsonCount(2, 'data.tags');
        $this->patchJson("/v1/media/{$item->id}", ['tags' => []])->assertOk()
            ->assertJsonPath('data.tags', []);

        expect(Tag::query()->withoutTenancy()->where('tenant_id', $this->acme->getKey())->count())->toBe(2)
            ->and(DB::table('taggables')->where('taggable_id', $item->id)->count())->toBe(0);
    });

    it('rejects tags without a slug, over-long tags and more than twenty', function (array $tags, string $field): void {
        $item = mediaItemIn($this->acme);

        $this->patchJson("/v1/media/{$item->id}", ['tags' => $tags])
            ->assertStatus(422)->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'punctuation only' => [['!!!'], 'tags.0'],
        'too long' => [[str_repeat('a', 41)], 'tags.0'],
        'too many' => [array_map(fn (int $i): string => "tag{$i}", range(1, 21)), 'tags'],
    ]);
});

describe('index', function (): void {
    it('lists ready items of the workspace, newest first, and nothing else', function (): void {
        mediaItemIn($this->acme, 'ready', ['name' => 'old.txt', 'created_at' => '2026-09-01 10:00:00']);
        mediaItemIn($this->acme, 'ready', ['name' => 'new.txt', 'created_at' => '2026-09-02 10:00:00']);
        mediaItemIn($this->acme, 'pending', ['name' => 'pending.txt']);
        mediaItemIn($this->acme, 'failed', ['name' => 'failed.txt']);
        mediaItemIn($this->acme, 'trashed', ['name' => 'trashed.txt']);
        mediaItemIn($this->globex, 'ready', ['name' => 'globex.txt']);

        expect(listedNames())->toBe(['new.txt', 'old.txt'])
            ->and(listedNames('filter[trashed]=true'))->toBe(['trashed.txt'])
            ->and(listedNames('filter[state]=trashed'))->toBe(['trashed.txt']);
    });

    it('paginates with page and per_page', function (): void {
        foreach (range(1, 5) as $i) {
            mediaItemIn($this->acme, 'ready', ['name' => "file-{$i}.txt"]);
        }

        $this->getJson('/v1/media?sort=name&per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.*.name', ['file-3.txt', 'file-4.txt'])
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3);
        $this->getJson('/v1/media?per_page=101')->assertStatus(422)->assertJsonStructure(['errors' => ['per_page']]);
    });

    it('sorts by the allow-listed fields only', function (): void {
        mediaItemIn($this->acme, 'ready', ['name' => 'b.txt', 'size_bytes' => 10]);
        mediaItemIn($this->acme, 'ready', ['name' => 'a.txt', 'size_bytes' => 30]);
        mediaItemIn($this->acme, 'ready', ['name' => 'c.txt', 'size_bytes' => 20]);

        expect(listedNames('sort=name'))->toBe(['a.txt', 'b.txt', 'c.txt'])
            ->and(listedNames('sort=-size_bytes'))->toBe(['a.txt', 'c.txt', 'b.txt']);
        $this->getJson('/v1/media?sort=storage_key')->assertStatus(422)->assertJsonStructure(['errors' => ['sort']]);
    });

    it('filters by folder, type, tag, uploader and creation date', function (): void {
        $folder = MediaFolder::factory()->forTenant($this->acme)->create();
        $other = createTenantUser($this->acme);
        taggedItem($this->acme, ['Invoices'], [
            'name' => 'invoice.pdf', 'mime_type' => 'application/pdf', 'folder_id' => $folder->id,
            'uploaded_by_user_id' => $this->user->id, 'created_at' => '2026-09-10 12:00:00',
        ]);
        taggedItem($this->acme, ['Screens'], [
            'name' => 'screen.png', 'mime_type' => 'image/png', 'uploaded_by_user_id' => $other->id,
            'created_at' => '2026-09-12 12:00:00',
        ]);
        mediaItemIn($this->acme, 'ready', ['name' => 'logs.zip', 'mime_type' => 'application/zip', 'created_at' => '2026-09-14 12:00:00']);

        expect(listedNames("filter[folder]={$folder->id}"))->toBe(['invoice.pdf'])
            ->and(listedNames("filter[folder_id]={$folder->id}"))->toBe(['invoice.pdf'])
            ->and(listedNames('filter[folder]=none&sort=name'))->toBe(['logs.zip', 'screen.png'])
            ->and(listedNames("filter[folder]=none,{$folder->id}"))->toHaveCount(3)
            ->and(listedNames('filter[type]=image'))->toBe(['screen.png'])
            ->and(listedNames('filter[type]=document,archive&sort=name'))->toBe(['invoice.pdf', 'logs.zip'])
            ->and(listedNames('filter[tag]=invoices'))->toBe(['invoice.pdf'])
            ->and(listedNames('filter[tag]=invoices,screens'))->toHaveCount(2)
            ->and(listedNames("filter[uploader]={$other->id}"))->toBe(['screen.png'])
            ->and(listedNames('filter[created_between]=2026-09-11,2026-09-12'))->toBe(['screen.png'])
            ->and(listedNames('filter[type]=image&filter[tag]=invoices'))->toBe([]);
    });

    it('reads created_between in the workspace time zone', function (): void {
        DB::table('tenants')->where('id', $this->acme->getKey())->update(['timezone' => 'Asia/Kathmandu']);
        // 19:00 UTC on the 10th is 00:45 on the 11th in Kathmandu.
        mediaItemIn($this->acme, 'ready', ['name' => 'late.txt', 'created_at' => '2026-09-10 19:00:00']);

        expect(listedNames('filter[created_between]=2026-09-11,2026-09-11'))->toBe(['late.txt'])
            ->and(listedNames('filter[created_between]=2026-09-10,2026-09-10'))->toBe([]);
    });

    it('searches names case-insensitively and treats wildcards literally', function (): void {
        mediaItemIn($this->acme, 'ready', ['name' => 'Quarterly_Report.txt']);
        mediaItemIn($this->acme, 'ready', ['name' => 'QuarterlyXReport.txt']);
        mediaItemIn($this->acme, 'ready', ['name' => '100% done.txt']);

        expect(listedNames('search=quarterly_rep'))->toBe(['Quarterly_Report.txt'])
            ->and(listedNames('search='.urlencode('0% d')))->toBe(['100% done.txt'])
            ->and(listedNames('search='.urlencode('%')))->toBe(['100% done.txt']);
    });

    it('answers 422 for unknown filters and invalid values', function (string $query, string $key): void {
        $this->getJson("/v1/media?{$query}")
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$key]]);
    })->with([
        ['filter[checksum]=abc', 'filter.checksum'],
        ['filter[folder]=everything', 'filter.folder'],
        ['filter[type]=video', 'filter.type'],
        ['filter[trashed]=maybe', 'filter.trashed'],
        ['filter[uploader]=1', 'filter.uploader'],
        ['filter[created_between]=yesterday,today', 'filter.created_between'],
    ]);

    it('shows the tickets an item is used in, directly and through comments', function (): void {
        [$contact, $category] = ticketPrerequisites($this->acme);
        $ticket = Ticket::factory()->forContact($contact, $category)->create();
        $item = mediaItemIn($this->acme, 'ready', ['name' => 'used.txt']);
        Mediable::factory()->forTenant($this->acme)->create(['media_item_id' => $item->id, 'mediable_type' => 'ticket', 'mediable_id' => $ticket->id]);

        $this->getJson('/v1/media')->assertOk()
            ->assertJsonPath('data.0.used_in_count', 1)
            ->assertJsonPath('data.0.used_in_tickets', [['number' => $ticket->number]]);
    });

    it('runs the same number of queries for ten items as for two', function (): void {
        [$contact, $category] = ticketPrerequisites($this->acme);
        $ticket = Ticket::factory()->forContact($contact, $category)->create();
        $seed = function (int $count) use ($ticket): void {
            foreach (range(1, $count) as $i) {
                $item = taggedItem($this->acme, ["tag-{$i}", 'shared'], ['uploaded_by_user_id' => $this->user->id]);
                Mediable::factory()->forTenant($this->acme)->create(['media_item_id' => $item->id, 'mediable_type' => 'ticket', 'mediable_id' => $ticket->id]);
            }
        };
        $countQueries = function (int $expectedItems): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/v1/media')->assertOk()->assertJsonCount($expectedItems, 'data');
            DB::disableQueryLog();

            // The tenancy settings written around every request (M3-07) are not queries of the list.
            return count(array_filter(DB::getQueryLog(), fn (array $query): bool => ! str_starts_with($query['query'], 'SELECT set_config(')));
        };

        $seed(2);
        // The first request of a test also loads the permission cache.
        $this->getJson('/v1/media')->assertOk();
        $forTwo = $countQueries(2);
        $seed(8);
        $forTen = $countQueries(10);

        expect($forTen)->toBe($forTwo)->and($forTen)->toBeLessThanOrEqual(12);
    });
});
