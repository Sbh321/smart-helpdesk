<?php

declare(strict_types=1);

use App\Modules\Platform\Models\PlatformUser;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * The exported OpenAPI document covers the whole public API (M3-06, docs/07-api/documentation.md):
 * every /v1 route of the router and the token endpoint, each with a response schema, a request schema
 * when the action validates input, problem details for errors, and no resource that degenerates to
 * `string` or an untyped value.
 */

/**
 * @return array<string, mixed>
 */
function openApiDocument(): array
{
    static $document = null;

    return $document ??= app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
}

/**
 * Routes of the public reference: everything under /v1 except the operator health check, plus POST /oauth/token.
 *
 * @return list<array{method: string, path: string, route: Route}>
 */
function publicApiRoutes(): array
{
    $routes = [];

    foreach (Router::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        $documented = str_starts_with($uri, 'v1/') && $uri !== 'v1/health' || $uri === 'oauth/token';
        if (! $documented) {
            continue;
        }

        // The document's server carries /v1 (ADR-0021); the token endpoint has its own server.
        $path = str_starts_with($uri, 'v1/') ? '/'.substr($uri, 3) : '/'.$uri;
        $path = (string) preg_replace('/\{(\w+)\?}/', '{$1}', $path);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[] = ['method' => strtolower($method), 'path' => $path, 'route' => $route];
        }
    }

    return $routes;
}

/**
 * @param  array<string, mixed>  $schema
 * @return array<string, mixed>
 */
function apiDocResolve(array $schema): array
{
    while (isset($schema['$ref'])) {
        $name = substr((string) $schema['$ref'], strlen('#/components/schemas/'));
        $schema = openApiDocument()['components']['schemas'][$name] ?? [];
    }

    return $schema;
}

/**
 * A schema that says nothing about the value: `{}`, or only a description or examples.
 *
 * @param  array<string, mixed>  $schema
 */
function apiDocIsUntyped(array $schema): bool
{
    return array_intersect(array_keys($schema), ['type', '$ref', 'anyOf', 'oneOf', 'allOf', 'enum', 'const']) === [];
}

function apiDocTakesFormRequest(Route $route): bool
{
    $action = $route->getActionName();
    if (! str_contains($action, '@')) {
        return false;
    }

    [$class, $method] = explode('@', $action);
    if (! method_exists($class, $method)) {
        return false;
    }

    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
            return true;
        }
    }

    return false;
}

it('documents every /v1 route and the token endpoint with a summary and a response schema', function (): void {
    $paths = openApiDocument()['paths'];
    $missing = [];
    $withoutResponse = [];
    $withoutRequestBody = [];
    $withoutSummary = [];

    $routes = publicApiRoutes();
    expect(count($routes))->toBeGreaterThan(140);

    foreach ($routes as ['method' => $method, 'path' => $path, 'route' => $route]) {
        $operation = $paths[$path][$method] ?? null;
        if ($operation === null) {
            $missing[] = strtoupper($method).' '.$path;

            continue;
        }

        $success = array_filter($operation['responses'] ?? [], fn (string|int $code): bool => str_starts_with((string) $code, '2') || str_starts_with((string) $code, '3'), ARRAY_FILTER_USE_KEY);
        $described = array_filter($success, fn (array $response, string|int $code): bool => in_array((int) $code, [204, 302], true)
            || ! apiDocIsUntyped(apiDocResolve($response['content']['application/json']['schema'] ?? [])), ARRAY_FILTER_USE_BOTH);
        if ($described === []) {
            $withoutResponse[] = strtoupper($method).' '.$path;
        }

        if (trim((string) ($operation['summary'] ?? '')) === '') {
            $withoutSummary[] = strtoupper($method).' '.$path;
        }

        if (apiDocTakesFormRequest($route) && in_array($method, ['post', 'put', 'patch'], true) && ! isset($operation['requestBody'])) {
            $withoutRequestBody[] = strtoupper($method).' '.$path;
        }
    }

    expect($missing)->toBe([])
        ->and($withoutResponse)->toBe([])
        ->and($withoutRequestBody)->toBe([])
        ->and($withoutSummary)->toBe([]);
});

it('has no resource whose schema degenerates to a string or an untyped value', function (): void {
    $degenerate = [];

    foreach (openApiDocument()['components']['schemas'] as $name => $schema) {
        if (! str_ends_with($name, 'Resource')) {
            continue;
        }

        $properties = $schema['properties'] ?? [];
        if (($schema['type'] ?? null) !== 'object' || ! is_array($properties) || $properties === [] || array_is_list($properties)) {
            $degenerate[] = $name;

            continue;
        }

        foreach ($properties as $property => $propertySchema) {
            $items = ($propertySchema['type'] ?? null) === 'array' ? ($propertySchema['items'] ?? $propertySchema['prefixItems'] ?? []) : null;
            if (apiDocIsUntyped($propertySchema) || $items === [] || is_array($items) && ! array_is_list($items) && apiDocIsUntyped($items)) {
                $degenerate[] = "{$name}.{$property}";
            }
        }
    }

    // No operation answers a bare string or an empty object where a resource exists.
    foreach (openApiDocument()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $code => $response) {
                $schema = apiDocResolve($response['content']['application/json']['schema'] ?? ['type' => 'object', 'properties' => ['_' => []]]);
                $data = isset($schema['properties']['data']) ? apiDocResolve($schema['properties']['data']) : $schema;
                if (($schema['type'] ?? null) === 'string' || ($data['type'] ?? null) === 'object' && empty($data['properties']) && ! isset($data['additionalProperties'])) {
                    $degenerate[] = strtoupper((string) $method)." {$path} {$code}";
                }
            }
        }
    }

    expect($degenerate)->toBe([]);
});

it('answers every error with problem details', function (): void {
    $document = openApiDocument();
    $notProblem = [];

    foreach ($document['components']['responses'] ?? [] as $name => $response) {
        if (array_keys($response['content'] ?? []) !== ['application/problem+json']) {
            $notProblem[] = "components.responses.{$name}";
        }
    }

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $code => $response) {
                if ((int) $code >= 400 && ! isset($response['$ref']) && array_keys($response['content'] ?? []) !== ['application/problem+json']) {
                    $notProblem[] = strtoupper((string) $method)." {$path} {$code}";
                }
            }

            $validates = isset($operation['requestBody']) && $path !== '/oauth/token';
            if ($validates && ! isset($operation['responses']['422'])) {
                $notProblem[] = strtoupper((string) $method)." {$path} lacks 422";
            }
        }
    }

    expect($notProblem)->toBe([]);
});

it('marks which routes API clients may call, with the scopes that open them', function (): void {
    $paths = openApiDocument()['paths'];

    expect($paths['/tickets']['get']['security'])->toBe([['session' => []], ['oauth2' => ['tickets:read']]])
        ->and($paths['/webhooks']['post']['security'])->toBe([['session' => []], ['oauth2' => ['webhooks:manage']]])
        ->and($paths['/me']['get']['security'])->toBe([['session' => []]])
        ->and($paths['/me']['get']['responses'])->toHaveKey('401')
        ->and($paths['/auth/login']['post']['security'])->toBe([])
        ->and($paths['/oauth/token']['post']['requestBody']['content'])->toHaveKey('application/x-www-form-urlencoded')
        ->and($paths['/oauth/token']['servers'][0]['url'])->toBe('https://'.config('helpdesk.hosts.api'));
});

describe('API reference access', function (): void {
    beforeEach(function (): void {
        $this->acme = createTenant('acme');
    });

    it('opens the reference to workspace users with integrations.manage', function (): void {
        $owner = actingAsRole($this->acme, 'owner');
        $this->actingAs($owner, 'web');

        $this->get('/docs/api')->assertOk()->assertSee('elements-api', false);
        $this->getJson('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'Smart Helpdesk API');
    });

    it('refuses the reference to an agent', function (): void {
        $agent = actingAsRole($this->acme, 'agent');
        $this->actingAs($agent, 'web');

        $this->getJson('/docs/api.json')->assertForbidden();
        $this->get('/docs/api')->assertForbidden();
    });

    it('sends guests to the sign-in page', function (): void {
        $this->get('/docs/api')->assertRedirect('https://'.config('helpdesk.hosts.app').'/login');
        $this->getJson('/docs/api.json')->assertUnauthorized();
    });

    it('opens the reference to Platform Super Admins on the admin host only', function (): void {
        $admin = PlatformUser::query()->create(['name' => 'Platform admin', 'email' => 'admin@platform.test', 'password' => 'platform-password']);
        $adminUrl = 'https://'.config('helpdesk.hosts.admin').'/platform-api/docs';

        $this->getJson($adminUrl.'/openapi.json')->assertUnauthorized();

        $this->actingAs($admin, 'platform');
        $this->get($adminUrl)->assertOk()->assertSee('elements-api', false);
        $this->getJson($adminUrl.'/openapi.json')->assertOk()->assertJsonPath('paths./tickets.get.operationId', 'tickets.index');
    });
});
