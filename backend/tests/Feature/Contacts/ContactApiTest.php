<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->user = actingAsRole($this->acme, 'agent');
});

function contactIn(Tenant $tenant, array $attributes = []): Contact
{
    return Contact::factory()->forTenant($tenant)->create($attributes);
}

describe('create and update', function (): void {
    it('creates a contact with an organisation and tags', function (): void {
        $organization = Organization::factory()->forTenant($this->acme)->create(['name' => 'Acme Retail']);

        $response = $this->postJson('/v1/contacts', [
            'name' => 'Priya Shrestha',
            'email' => 'Priya@Example.test',
            'phone' => '+977 1 4000000',
            'organization_id' => $organization->id,
            'tags' => ['vip', 'nepal'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'Priya@Example.test')
            ->assertJsonPath('data.organization.name', 'Acme Retail')
            ->assertJsonPath('data.organization.tier', 'standard')
            ->assertJsonCount(2, 'data.tags')
            ->assertJsonPath('data.archived_at', null);

        $contact = Contact::query()->withoutTenancy()->findOrFail($response->json('data.id'));

        expect($contact->tenant_id)->toBe($this->acme->id)
            ->and(AuditLog::query()->where('action', 'contact.created')->count())->toBe(1);
    });

    it('rejects a duplicate email in the same workspace, ignoring case', function (): void {
        contactIn($this->acme, ['email' => 'priya@example.test']);

        $this->postJson('/v1/contacts', ['name' => 'Another Priya', 'email' => 'PRIYA@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['email']]);
    });

    it('allows the same email in another workspace', function (): void {
        contactIn($this->globex, ['email' => 'priya@example.test']);

        $this->postJson('/v1/contacts', ['name' => 'Priya', 'email' => 'priya@example.test'])->assertCreated();
    });

    it('refuses an organisation from another workspace', function (): void {
        $foreign = Organization::factory()->forTenant($this->globex)->create();

        $this->postJson('/v1/contacts', ['name' => 'Priya', 'email' => 'p@example.test', 'organization_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['organization_id']]);
    });

    it('keeps the same email when updating the contact itself', function (): void {
        $contact = contactIn($this->acme, ['email' => 'priya@example.test']);

        $this->patchJson("/v1/contacts/{$contact->id}", ['email' => 'PRIYA@example.test', 'name' => 'Priya S.'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Priya S.');
    });

    it('replaces tags on update and leaves them alone when omitted', function (): void {
        $contact = contactIn($this->acme);

        $this->patchJson("/v1/contacts/{$contact->id}", ['tags' => ['vip']])->assertOk()->assertJsonCount(1, 'data.tags');
        $this->patchJson("/v1/contacts/{$contact->id}", ['phone' => '9800000000'])->assertOk()->assertJsonCount(1, 'data.tags');
        $this->patchJson("/v1/contacts/{$contact->id}", ['tags' => []])->assertOk()->assertJsonCount(0, 'data.tags');
    });

    it('validates the payload', function (array $payload, string $field): void {
        $this->postJson('/v1/contacts', ['name' => 'Priya', 'email' => 'p@example.test', ...$payload])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => [$field]]);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'bad email' => [['email' => 'not-an-email'], 'email'],
        'bad phone' => [['phone' => 'call me maybe'], 'phone'],
        'too much metadata' => [['metadata' => ['blob' => str_repeat('x', 9000)]], 'metadata'],
    ]);

    it('records the change history of a contact', function (): void {
        $id = $this->postJson('/v1/contacts', ['name' => 'Priya', 'email' => 'p@example.test'])->json('data.id');
        $this->patchJson("/v1/contacts/{$id}", ['name' => 'Priya Shrestha'])->assertOk();

        $operations = DB::table('entity_changes')->where('entity_type', 'contacts')->where('entity_id', $id)
            ->orderBy('version')->pluck('operation')->all();

        expect($operations)->toBe(['insert', 'update']);
    });
});

describe('archive', function (): void {
    it('archives and restores a contact', function (): void {
        $contact = contactIn($this->acme);

        $this->postJson("/v1/contacts/{$contact->id}/archive")->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->getJson('/v1/contacts')->assertJsonCount(0, 'data');
        $this->getJson('/v1/contacts?filter[archived]=true')->assertJsonCount(1, 'data');

        $this->postJson("/v1/contacts/{$contact->id}/unarchive")->assertOk()->assertJsonPath('data.archived_at', null);
        $this->getJson('/v1/contacts')->assertJsonCount(1, 'data');
    });
});

describe('list', function (): void {
    beforeEach(function (): void {
        $this->retail = Organization::factory()->forTenant($this->acme)->create(['name' => 'Retail']);
        contactIn($this->acme, ['name' => 'Asha Gurung', 'email' => 'asha@retail.test', 'organization_id' => $this->retail->id]);
        contactIn($this->acme, ['name' => 'Bikash Rai', 'email' => 'bikash@example.test']);
        contactIn($this->acme, ['name' => 'Chandra Thapa', 'email' => 'chandra@example.test']);
        contactIn($this->globex, ['name' => 'Aaron Globex', 'email' => 'aaron@globex.test']);
    });

    it('lists only this workspace, sorted by name, with pagination meta', function (): void {
        $this->getJson('/v1/contacts?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Asha Gurung')
            ->assertJsonPath('data.1.name', 'Bikash Rai')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2);
    });

    it('sorts descending and by other fields', function (): void {
        $this->getJson('/v1/contacts?sort=-name')->assertJsonPath('data.0.name', 'Chandra Thapa');
        $this->getJson('/v1/contacts?sort=email')->assertJsonPath('data.0.email', 'asha@retail.test');
    });

    it('filters by organisation, including contacts without one', function (): void {
        $this->getJson("/v1/contacts?filter[organization_id]={$this->retail->id}")->assertJsonCount(1, 'data');
        $this->getJson('/v1/contacts?filter[organization_id]=none')->assertJsonCount(2, 'data');
        $this->getJson("/v1/contacts?filter[organization_id]={$this->retail->id},none")->assertJsonCount(3, 'data');
    });

    it('filters by tag', function (): void {
        $contact = Contact::query()->withoutTenancy()->where('name', 'Bikash Rai')->sole();
        $this->acme->run(fn () => $contact->syncTagNames(['vip']));

        $this->getJson('/v1/contacts?filter[tag]=vip')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bikash Rai');
    });

    it('searches name and email', function (): void {
        $this->getJson('/v1/contacts?search=chandra')->assertJsonCount(1, 'data');
        $this->getJson('/v1/contacts?search=example.test')->assertJsonCount(2, 'data');
        $this->getJson('/v1/contacts?search=aaron')->assertJsonCount(0, 'data');
    });

    it('rejects unknown sort fields and filters', function (): void {
        $this->getJson('/v1/contacts?sort=password')->assertStatus(422)->assertJsonStructure(['errors' => ['sort']]);
        $this->getJson('/v1/contacts?filter[tenant_id]=x')->assertStatus(422)->assertJsonStructure(['errors' => ['filter.tenant_id']]);
        $this->getJson('/v1/contacts?filter[organization_id]=nope')->assertStatus(422);
        $this->getJson('/v1/contacts?per_page=500')->assertStatus(422);
    });

    it('returns an empty page past the end', function (): void {
        $this->getJson('/v1/contacts?page=9')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('typeahead', function (): void {
    it('returns fuzzy matches, best first', function (): void {
        contactIn($this->acme, ['name' => 'Priya Shrestha', 'email' => 'priya@example.test']);
        contactIn($this->acme, ['name' => 'Pradeep Karki', 'email' => 'pradeep@example.test']);
        contactIn($this->globex, ['name' => 'Priya Other', 'email' => 'priya@globex.test']);

        $this->getJson('/v1/contacts/typeahead?q=priya')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Priya Shrestha');

        // A typo still finds the contact through trigram similarity.
        $this->getJson('/v1/contacts/typeahead?q=shresta')->assertOk()->assertJsonPath('data.0.name', 'Priya Shrestha');
    });

    it('requires a search term', function (): void {
        $this->getJson('/v1/contacts/typeahead')->assertStatus(422);
    });
});

describe('isolation and permissions', function (): void {
    it('answers 404 for another workspace contact', function (): void {
        $foreign = contactIn($this->globex);

        $this->getJson("/v1/contacts/{$foreign->id}")->assertNotFound();
        $this->patchJson("/v1/contacts/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/v1/contacts/{$foreign->id}/archive")->assertNotFound();
    });

    it('lets a developer read contacts but not change them', function (): void {
        $developer = actingAsRole($this->acme, 'developer', createTenantUser($this->acme));
        $contact = contactIn($this->acme);

        $this->getJson('/v1/contacts')->assertOk();
        $this->getJson("/v1/contacts/{$contact->id}")->assertOk();
        $this->postJson('/v1/contacts', ['name' => 'x', 'email' => 'x@example.test'])->assertForbidden();
    });
});
