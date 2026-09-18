<?php

declare(strict_types=1);

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Contacts\Models\Tag;

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    actingAsRole($this->acme, 'admin');
});

it('creates, lists and updates organisations', function (): void {
    $id = $this->postJson('/v1/organizations', ['name' => 'Acme Retail', 'domain' => 'retail.test', 'tier' => 'premium', 'tags' => ['key-account']])
        ->assertCreated()
        ->assertJsonPath('data.tier', 'premium')
        ->assertJsonPath('data.contacts_count', 0)
        ->json('data.id');

    Contact::factory()->forTenant($this->acme)->create(['organization_id' => $id]);

    $this->getJson('/v1/organizations')->assertOk()->assertJsonPath('data.0.contacts_count', 1);
    $this->getJson('/v1/organizations?filter[tier]=enterprise')->assertJsonCount(0, 'data');

    $this->patchJson("/v1/organizations/{$id}", ['tier' => 'enterprise'])->assertOk()->assertJsonPath('data.tier', 'enterprise');
});

it('keeps organisation names unique per workspace, ignoring case', function (): void {
    Organization::factory()->forTenant($this->acme)->create(['name' => 'Acme Retail']);
    Organization::factory()->forTenant($this->globex)->create(['name' => 'Globex Retail']);

    $this->postJson('/v1/organizations', ['name' => 'ACME retail'])->assertStatus(422);
    $this->postJson('/v1/organizations', ['name' => 'Globex Retail'])->assertCreated();
});

it('rejects an unknown tier', function (): void {
    $this->postJson('/v1/organizations', ['name' => 'X', 'tier' => 'platinum'])->assertStatus(422);
});

it('hides another workspace organisation', function (): void {
    $foreign = Organization::factory()->forTenant($this->globex)->create();

    $this->getJson("/v1/organizations/{$foreign->id}")->assertNotFound();
});

it('creates, searches and deletes tags within the workspace', function (): void {
    $this->postJson('/v1/tags', ['name' => 'Billing Issue', 'color' => 'accent-3'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'billing-issue');

    $this->postJson('/v1/tags', ['name' => 'billing issue'])->assertStatus(422);

    $this->getJson('/v1/tags?search=bill')->assertOk()->assertJsonCount(1, 'data');

    $tag = Tag::query()->withoutTenancy()->where('slug', 'billing-issue')->sole();
    $this->deleteJson("/v1/tags/{$tag->id}")->assertNoContent();
    $this->getJson('/v1/tags')->assertJsonCount(0, 'data');
});

it('never shares tags between workspaces', function (): void {
    $this->globex->run(fn () => Tag::query()->create(['name' => 'VIP', 'slug' => 'vip']));

    $this->getJson('/v1/tags')->assertJsonCount(0, 'data');
    $this->postJson('/v1/tags', ['name' => 'VIP'])->assertCreated();
});
