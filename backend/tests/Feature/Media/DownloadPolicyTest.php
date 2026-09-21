<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tickets\Models\Ticket;
use App\Modules\Tickets\Models\TicketComment;

require_once __DIR__.'/MediaTestSupport.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * Download policy (docs/03-architecture/storage.md §Download flow): tenant scope and `media.view`
 * first; then a file that only lives on tickets follows `tickets.view`, one that only lives on
 * internal notes follows `comments.internal` too, and the uploader always reads their own file.
 */

beforeEach(function (): void {
    useMediaTestDisk();
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    app(SyncPermissionCatalogue::class)();
    [$contact, $category] = ticketPrerequisites($this->acme);
    $this->ticket = Ticket::factory()->forContact($contact, $category)->create();
});

afterEach(fn () => cleanMediaTestDisk());

/** @param list<string> $permissions */
function actingWithPermissions(array $permissions): User
{
    $user = createTenantUser(test()->acme);
    actingAsTenantUser(test()->acme, $user);
    test()->acme->run(fn () => $user->givePermissionTo($permissions));

    return $user;
}

function linkTo(MediaItem $item, string $type, string $subjectId): void
{
    Mediable::factory()->forTenant(test()->acme)->create([
        'media_item_id' => $item->id, 'mediable_type' => $type, 'mediable_id' => $subjectId,
    ]);
}

function commentOn(Ticket $ticket, string $visibility): TicketComment
{
    return TicketComment::factory()->forTenant(test()->acme)->create(['ticket_id' => $ticket->id, 'visibility' => $visibility]);
}

it('redirects to a short-lived signed URL of the media key, never the staging key', function (): void {
    actingAsRole($this->acme, 'agent');
    $item = uploadReady($this->acme, 'notes.txt', "hello\n");

    $location = (string) $this->get("/v1/media/{$item->id}/download")->assertRedirect()->headers->get('Location');

    expect($location)->toContain("media/{$item->id}/original.txt")
        ->and($location)->toContain('signature=')
        ->and($location)->toContain('expires=')
        ->and($location)->not->toContain('uploads/');
});

it('opens an unlinked library file to everyone with media.view', function (): void {
    $item = mediaItemIn($this->acme);
    actingWithPermissions(['media.view']);

    $this->get("/v1/media/{$item->id}/download")->assertRedirect();
});

it('follows tickets.view for a file attached to a ticket', function (): void {
    $item = mediaItemIn($this->acme);
    linkTo($item, 'ticket', $this->ticket->id);

    actingWithPermissions(['media.view']);
    $this->getJson("/v1/media/{$item->id}/download")->assertForbidden()->assertJsonPath('code', 'forbidden');

    actingWithPermissions(['media.view', 'tickets.view']);
    $this->get("/v1/media/{$item->id}/download")->assertRedirect();
});

it('follows tickets.view for a public reply and comments.internal for an internal note', function (): void {
    $onReply = mediaItemIn($this->acme);
    $onNote = mediaItemIn($this->acme);
    linkTo($onReply, 'ticket_comment', commentOn($this->ticket, 'public')->id);
    linkTo($onNote, 'ticket_comment', commentOn($this->ticket, 'internal')->id);

    actingWithPermissions(['media.view']);
    $this->getJson("/v1/media/{$onReply->id}/download")->assertForbidden();
    $this->getJson("/v1/media/{$onNote->id}/download")->assertForbidden();

    actingWithPermissions(['media.view', 'tickets.view']);
    $this->get("/v1/media/{$onReply->id}/download")->assertRedirect();
    $this->getJson("/v1/media/{$onNote->id}/download")->assertForbidden();

    actingWithPermissions(['media.view', 'comments.internal']);   // an internal note is still ticket content
    $this->getJson("/v1/media/{$onNote->id}/download")->assertForbidden();

    actingWithPermissions(['media.view', 'tickets.view', 'comments.internal']);
    $this->get("/v1/media/{$onNote->id}/download")->assertRedirect();
});

it('opens a file when any one of its links is visible', function (): void {
    $item = mediaItemIn($this->acme);
    linkTo($item, 'ticket_comment', commentOn($this->ticket, 'internal')->id);
    actingWithPermissions(['media.view', 'tickets.view']);
    $this->getJson("/v1/media/{$item->id}/download")->assertForbidden();

    linkTo($item, 'ticket', $this->ticket->id);
    $this->get("/v1/media/{$item->id}/download")->assertRedirect();
});

it('treats links that are not tickets as library use, and a vanished comment as nothing', function (): void {
    $branding = mediaItemIn($this->acme);
    $orphaned = mediaItemIn($this->acme);
    linkTo($branding, 'tenant_branding', $this->acme->getKey());
    linkTo($orphaned, 'ticket_comment', '01920000-0000-7000-8000-000000000000');
    actingWithPermissions(['media.view', 'tickets.view', 'comments.internal']);

    $this->get("/v1/media/{$branding->id}/download")->assertRedirect();
    $this->getJson("/v1/media/{$orphaned->id}/download")->assertForbidden();
});

it('always lets the uploader read their own file back', function (): void {
    $user = actingWithPermissions(['media.view']);
    $item = mediaItemIn($this->acme, 'ready', ['uploaded_by_user_id' => $user->id]);
    linkTo($item, 'ticket', $this->ticket->id);

    $this->get("/v1/media/{$item->id}/download")->assertRedirect();
});

it('answers 404 for another tenant and for items that are not ready', function (): void {
    actingAsRole($this->acme, 'admin');
    $foreign = mediaItemIn($this->globex);

    $this->getJson("/v1/media/{$foreign->id}/download")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->getJson("/v1/media/{$foreign->id}/variants/thumb")->assertNotFound();
    foreach (['pending', 'failed', 'trashed'] as $state) {
        $this->getJson('/v1/media/'.mediaItemIn($this->acme, $state)->id.'/download')->assertNotFound();
    }
});

describe('variants', function (): void {
    it('signs only a variant key this server generated for the item', function (): void {
        actingAsRole($this->acme, 'agent');
        $item = MediaItem::factory()->forTenant($this->acme)->ready()->image()->create();
        $other = MediaItem::factory()->forTenant($this->acme)->ready()->image()->create();
        $this->acme->run(function () use ($item, $other): void {
            $item->forceFill(['variants' => [
                'thumb' => ['key' => MediaKeys::variant($item->id, 'thumb'), 'width' => 240, 'height' => 180],
                // A tampered row must not make the API sign somebody else's object.
                'preview' => ['key' => $other->storage_key, 'width' => 1, 'height' => 1],
            ]])->save();
        });

        $location = (string) $this->get("/v1/media/{$item->id}/variants/thumb")->assertRedirect()->headers->get('Location');
        expect($location)->toContain("media/{$item->id}/thumb.webp");

        $this->getJson("/v1/media/{$item->id}/variants/preview")->assertNotFound();
        $this->getJson("/v1/media/{$other->id}/variants/thumb")->assertNotFound();
        $this->getJson("/v1/media/{$item->id}/variants/original")->assertNotFound();
    });

    it('applies the download policy to variants', function (): void {
        $item = MediaItem::factory()->forTenant($this->acme)->ready()->image()->create();
        $this->acme->run(fn () => $item->forceFill(['variants' => [
            'thumb' => ['key' => MediaKeys::variant($item->id, 'thumb'), 'width' => 240, 'height' => 180],
        ]])->save());
        linkTo($item, 'ticket', $this->ticket->id);

        actingWithPermissions(['media.view']);
        $this->getJson("/v1/media/{$item->id}/variants/thumb")->assertForbidden();
    });
});
