<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Integrations\Http\Middleware\RestrictApiClients;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Per-route facts Scramble cannot infer from our middleware groups (docs/07-api/documentation.md §Access):
 *
 * - which principals may call the route: every authenticated route accepts the SPA session; only routes
 *   marked `api-clients` also accept an API client token, with the scopes that grant the route's
 *   `can:` permission (ScopeMap); the rest answer a client with 403;
 * - the permission the route checks, written into the description;
 * - a free-form JSON body declared with {@see FreeFormRequestBody}.
 */
final class OperationConventions implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $this->documentFreeFormBody($operation, $routeInfo);

        // `@unauthenticated` routes (login, password reset, ping) already carry an empty security list.
        if ($operation->security === []) {
            return;
        }

        $permissions = self::permissions($routeInfo);
        $notes = [];

        if ($permissions !== []) {
            $notes[] = 'Requires permission '.implode(' or ', array_map(fn (string $p): string => "`{$p}`", $permissions)).'.';
        }

        $operation->security = [new SecurityRequirement(['session' => []])];

        if (RestrictApiClients::allowsClients($routeInfo->route)) {
            $scopes = self::scopesGranting($permissions);
            $operation->security[] = new SecurityRequirement(['oauth2' => $scopes]);
            $notes[] = $scopes === []
                ? 'Open to API clients.'
                : 'Open to API clients with scope '.implode(' or ', array_map(fn (string $s): string => "`{$s}`", $scopes)).'.';
        } else {
            $notes[] = 'SPA session only: an API client token is answered with `403 forbidden`.';
        }

        $operation->description(trim($operation->description."\n\n".implode(' ', $notes)));
    }

    /**
     * @return list<string>
     */
    private static function permissions(RouteInfo $routeInfo): array
    {
        $permissions = [];

        foreach ($routeInfo->route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $permissions[] = explode(',', substr($middleware, 4))[0];
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private static function scopesGranting(array $permissions): array
    {
        $scopes = [];

        foreach (ScopeMap::MAP as $scope => $granted) {
            if (array_intersect($permissions, $granted) !== []) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    private function documentFreeFormBody(Operation $operation, RouteInfo $routeInfo): void
    {
        $attribute = $routeInfo->reflectionMethod()?->getAttributes(FreeFormRequestBody::class)[0] ?? null;

        if ($attribute === null) {
            return;
        }

        $body = $attribute->newInstance();
        $type = (new ObjectType)->additionalProperties(new MixedType);
        $type->setDescription($body->description);

        if ($body->example !== null) {
            $type->examples([$body->example]);
        }

        $operation->addRequestBodyObject(
            RequestBodyObject::make()->setContent('application/json', Schema::fromType($type))->required(true),
        );
    }
}
