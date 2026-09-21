<?php

declare(strict_types=1);

use App\Modules\Media\Models\MediaFolder;
use App\Modules\Tenancy\Models\Tenant;

require_once __DIR__.'/MediaTestSupport.php';

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'manager');
});

function folderIn(Tenant $tenant, string $name, ?MediaFolder $parent = null, array $attributes = []): MediaFolder
{
    return MediaFolder::factory()->forTenant($tenant)->create(['name' => $name, 'parent_id' => $parent?->id, ...$attributes]);
}

/** A chain of nested folders, outermost first. */
function folderChain(Tenant $tenant, int $levels, string $prefix = 'Level'): array
{
    $chain = [];
    foreach (range(1, $levels) as $level) {
        $chain[] = folderIn($tenant, "{$prefix} {$level}", $chain[count($chain) - 1] ?? null);
    }

    return $chain;
}

describe('list and create', function (): void {
    it('lists the folders of the workspace by name and never those of another tenant', function (): void {
        folderIn($this->acme, 'Manuals');
        folderIn($this->acme, 'Contracts');
        folderIn($this->globex, 'Globex secrets');

        $names = $this->getJson('/v1/media/folders')->assertOk()->json('data.*.name');

        expect($names)->toBe(['Contracts', 'Manuals']);
    });

    it('creates a root folder and a nested folder', function (): void {
        $root = $this->postJson('/v1/media/folders', ['name' => '  Manuals  '])->assertCreated()
            ->assertJsonPath('data.name', 'Manuals')
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.system_key', null)
            ->json('data.id');

        $this->postJson('/v1/media/folders', ['name' => 'Printers', 'parent_id' => $root])->assertCreated()
            ->assertJsonPath('data.parent_id', $root);

        expect(MediaFolder::query()->withoutTenancy()->where('tenant_id', $this->acme->getKey())->count())->toBe(2);
    });

    it('rejects a missing or over-long name', function (array $payload): void {
        $this->postJson('/v1/media/folders', $payload)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['name']]);
    })->with([
        'missing' => [[]],
        'too long' => [['name' => str_repeat('a', 121)]],
    ]);

    it('rejects a sibling with the same name, whatever the case, but allows it elsewhere', function (): void {
        $manuals = folderIn($this->acme, 'Manuals');
        folderIn($this->globex, 'Shared name');

        $this->postJson('/v1/media/folders', ['name' => 'manuals'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->postJson('/v1/media/folders', ['name' => 'Manuals', 'parent_id' => $manuals->id])->assertCreated();
        $this->postJson('/v1/media/folders', ['name' => 'Shared name'])->assertCreated();
    });

    it('nests at most five levels', function (): void {
        $chain = folderChain($this->acme, 4);

        $fifth = $this->postJson('/v1/media/folders', ['name' => 'Level 5', 'parent_id' => $chain[3]->id])
            ->assertCreated()->json('data.id');
        $this->postJson('/v1/media/folders', ['name' => 'Level 6', 'parent_id' => $fifth])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['parent_id']]);
    });

    it('answers 404 for a parent of another tenant', function (): void {
        $foreign = folderIn($this->globex, 'Globex');

        $this->postJson('/v1/media/folders', ['name' => 'Inside', 'parent_id' => $foreign->id])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
    });
});

describe('rename and move', function (): void {
    it('renames a folder and keeps its parent', function (): void {
        $parent = folderIn($this->acme, 'Manuals');
        $folder = folderIn($this->acme, 'Printers', $parent);

        $this->patchJson("/v1/media/folders/{$folder->id}", ['name' => ' Scanners '])->assertOk()
            ->assertJsonPath('data.name', 'Scanners')
            ->assertJsonPath('data.parent_id', $parent->id);
    });

    it('moves a folder under another one and back to the root', function (): void {
        $manuals = folderIn($this->acme, 'Manuals');
        $folder = folderIn($this->acme, 'Printers');

        $this->patchJson("/v1/media/folders/{$folder->id}", ['parent_id' => $manuals->id])->assertOk()
            ->assertJsonPath('data.parent_id', $manuals->id)
            ->assertJsonPath('data.name', 'Printers');
        $this->patchJson("/v1/media/folders/{$folder->id}", ['parent_id' => null])->assertOk()
            ->assertJsonPath('data.parent_id', null);
    });

    it('rejects a rename or a move that collides with a sibling', function (): void {
        $manuals = folderIn($this->acme, 'Manuals');
        folderIn($this->acme, 'Printers', $manuals);
        $other = folderIn($this->acme, 'Printers');

        $this->patchJson("/v1/media/folders/{$other->id}", ['name' => 'MANUALS'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);
        $this->patchJson("/v1/media/folders/{$other->id}", ['parent_id' => $manuals->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

        expect($other->fresh()?->parent_id)->toBeNull();
    });

    it('refuses to move a folder into itself or one of its descendants', function (): void {
        [$top, $middle, $bottom] = folderChain($this->acme, 3);

        foreach ([$top, $middle, $bottom] as $target) {
            $this->patchJson("/v1/media/folders/{$top->id}", ['parent_id' => $target->id])
                ->assertStatus(422)->assertJsonStructure(['errors' => ['parent_id']]);
        }
    });

    it('counts the moved subtree against the five levels', function (): void {
        $deep = folderChain($this->acme, 3, 'Deep');
        $moved = folderChain($this->acme, 3, 'Moved');

        // 3 levels above + a subtree 3 high = 6.
        $this->patchJson("/v1/media/folders/{$moved[0]->id}", ['parent_id' => $deep[2]->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['parent_id']]);
        // 2 levels above + 3 = 5.
        $this->patchJson("/v1/media/folders/{$moved[0]->id}", ['parent_id' => $deep[1]->id])->assertOk();
    });

    it('answers 404 for a folder or a target of another tenant', function (): void {
        $foreign = folderIn($this->globex, 'Globex');
        $own = folderIn($this->acme, 'Manuals');

        $this->patchJson("/v1/media/folders/{$foreign->id}", ['name' => 'Taken'])->assertNotFound();
        $this->patchJson("/v1/media/folders/{$own->id}", ['parent_id' => $foreign->id])->assertNotFound();
        $this->patchJson('/v1/media/folders/not-a-uuid', ['name' => 'x'])->assertNotFound();

        expect($this->globex->run(fn () => $foreign->fresh())?->name)->toBe('Globex');
    });
});

describe('delete', function (): void {
    it('deletes an empty folder', function (): void {
        $folder = folderIn($this->acme, 'Empty');

        $this->deleteJson("/v1/media/folders/{$folder->id}")->assertNoContent();

        expect($folder->fresh())->toBeNull();
    });

    it('answers 409 in_use while the folder holds a folder or a Media item', function (): void {
        $withChild = folderIn($this->acme, 'Parent');
        folderIn($this->acme, 'Child', $withChild);
        $withItem = folderIn($this->acme, 'Files');
        // Trashed items still sit in their folder and come back on restore.
        mediaItemIn($this->acme, 'trashed', ['folder_id' => $withItem->id]);

        foreach ([$withChild, $withItem] as $folder) {
            $this->deleteJson("/v1/media/folders/{$folder->id}")
                ->assertStatus(409)->assertJsonPath('code', 'in_use');
            expect($folder->fresh())->not->toBeNull();
        }
    });

    it('protects system folders from rename, move and delete', function (): void {
        $system = folderIn($this->acme, 'Tickets', null, ['system_key' => 'tickets']);

        $this->patchJson("/v1/media/folders/{$system->id}", ['name' => 'Renamed'])
            ->assertStatus(409)->assertJsonPath('code', 'conflict')->assertJsonPath('meta.reason', 'system_folder');
        $this->deleteJson("/v1/media/folders/{$system->id}")
            ->assertStatus(409)->assertJsonPath('meta.reason', 'system_folder');

        // Ordinary folders can still live inside one.
        $this->postJson('/v1/media/folders', ['name' => 'Archive', 'parent_id' => $system->id])->assertCreated();
        expect($system->fresh()?->name)->toBe('Tickets');
    });

    it('answers 404 for a folder of another tenant', function (): void {
        $foreign = folderIn($this->globex, 'Globex');

        $this->deleteJson("/v1/media/folders/{$foreign->id}")->assertNotFound();

        expect($this->globex->run(fn (): bool => MediaFolder::query()->whereKey($foreign->id)->exists()))->toBeTrue();
    });
});
