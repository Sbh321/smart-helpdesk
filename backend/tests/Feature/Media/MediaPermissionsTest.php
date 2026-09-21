<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Identity\Support\PermissionCatalogue;
use App\Modules\Media\Models\MediaFolder;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/MediaTestSupport.php';

/*
 * Every Media route answers 403 to a user who holds every permission EXCEPT the one the route
 * names, and the matrix below is checked against the router so a new route cannot be forgotten.
 */

const MEDIA_ROUTE_PERMISSIONS = [
    'media.intent' => ['POST', '/v1/media/intent', 'media.upload'],
    'media.complete' => ['POST', '/v1/media/{pending}/complete', 'media.upload'],
    'media.folders.index' => ['GET', '/v1/media/folders', 'media.view'],
    'media.folders.store' => ['POST', '/v1/media/folders', 'media.manage'],
    'media.folders.update' => ['PATCH', '/v1/media/folders/{folder}', 'media.manage'],
    'media.folders.destroy' => ['DELETE', '/v1/media/folders/{folder}', 'media.manage'],
    'media.index' => ['GET', '/v1/media', 'media.view'],
    'media.usage' => ['GET', '/v1/media/usage', 'media.view'],
    'media.show' => ['GET', '/v1/media/{ready}', 'media.view'],
    'media.download' => ['GET', '/v1/media/{ready}/download', 'media.view'],
    'media.variant' => ['GET', '/v1/media/{ready}/variants/thumb', 'media.view'],
    'media.update' => ['PATCH', '/v1/media/{ready}', 'media.manage'],
    'media.trash' => ['POST', '/v1/media/{ready}/trash', 'media.manage'],
    'media.restore' => ['POST', '/v1/media/{trashed}/restore', 'media.manage'],
    'media.destroy' => ['DELETE', '/v1/media/{trashed}', 'media.manage'],
];

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    app(SyncPermissionCatalogue::class)();
    $this->user = createTenantUser($this->acme);
    actingAsTenantUser($this->acme, $this->user);
});

function mediaRouteUri(string $template): string
{
    $acme = test()->acme;

    return strtr($template, [
        '{pending}' => mediaItemIn($acme, 'pending', ['uploaded_by_user_id' => test()->user->id])->id,
        '{ready}' => mediaItemIn($acme, 'ready')->id,
        '{trashed}' => mediaItemIn($acme, 'trashed')->id,
        '{folder}' => MediaFolder::factory()->forTenant($acme)->create()->id,
    ]);
}

it('answers 403 without the permission of the route', function (string $method, string $uri, string $permission): void {
    $others = array_values(array_diff(PermissionCatalogue::all(), [$permission]));
    $this->acme->run(fn () => $this->user->givePermissionTo($others));

    $this->json($method, mediaRouteUri($uri), ['name' => 'x.txt', 'filename' => 'x.txt', 'size' => 1, 'mime' => 'text/plain'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
})->with(MEDIA_ROUTE_PERMISSIONS);

it('lets the request through with only that permission', function (string $method, string $uri, string $permission): void {
    $this->acme->run(fn () => $this->user->givePermissionTo($permission));

    $status = $this->json($method, mediaRouteUri($uri), ['name' => 'x.txt', 'filename' => 'x.txt', 'size' => 1, 'mime' => 'text/plain'])->status();

    expect($status)->not->toBe(403)->and($status)->not->toBe(401);
})->with(MEDIA_ROUTE_PERMISSIONS);

it('covers every route of the module', function (): void {
    $routes = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, string $name): bool => str_starts_with($name, 'media.'));

    expect($routes->keys()->sort()->values()->all())->toBe(collect(array_keys(MEDIA_ROUTE_PERMISSIONS))->sort()->values()->all());
    foreach ($routes as $name => $route) {
        expect($route->gatherMiddleware())->toContain('can:'.MEDIA_ROUTE_PERMISSIONS[$name][2]);
    }
});

it('gives agents view and upload, managers manage, and developers view only', function (string $role, array $allowed, array $denied): void {
    actingAsRole($this->acme, $role, $this->user);

    foreach ($allowed as $permission) {
        expect($this->acme->run(fn () => $this->user->can($permission)))->toBeTrue("{$role} should have {$permission}");
    }
    foreach ($denied as $permission) {
        expect($this->acme->run(fn () => $this->user->can($permission)))->toBeFalse("{$role} should not have {$permission}");
    }
})->with([
    ['agent', ['media.view', 'media.upload'], ['media.manage']],
    ['manager', ['media.view', 'media.upload', 'media.manage'], []],
    ['developer', ['media.view'], ['media.upload', 'media.manage']],
]);

it('answers 401 to a guest', function (): void {
    $this->app['auth']->forgetGuards();

    $this->getJson('/v1/media')->assertUnauthorized();
});
