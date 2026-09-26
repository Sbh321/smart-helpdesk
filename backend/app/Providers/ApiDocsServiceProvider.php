<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Integrations\Domain\ScopeMap;
use App\Modules\Platform\Models\PlatformUser;
use App\Support\ApiDocs\OperationConventions;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Path;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\SecuritySchemes\OAuthFlow;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * OpenAPI document setup (ADR-0010, docs/07-api/documentation.md).
 *
 * Adds the server, the security schemes, the token endpoint and the shared RFC 9457 problem-details
 * schema; rewrites every error response to that schema so generated clients get one error type
 * (docs/03-architecture/error-handling.md); copies resource class summaries into their schemas.
 * Per-route access (session only or API clients with scopes) comes from {@see OperationConventions}.
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public const PROBLEM_SCHEMA = 'ProblemDetails';

    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    public function boot(): void
    {
        // The reference is for integrators: workspace users who manage integrations, and Platform
        // Super Admins (M3-06). The routes live in routes/web.php and Platform/Routes/platform.php.
        Gate::define('viewApiDocs', fn (?Authenticatable $user = null): bool => $user instanceof PlatformUser
            || ($user instanceof User && $user->can('integrations.manage')));

        // Scramble's own routes would bypass that gate; ours replace them.
        Scramble::configure()->expose(false);

        Scramble::configure()
            ->withOperationTransformers(OperationConventions::class)
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $api = 'https://'.config('helpdesk.hosts.api');

                $openApi->servers = [Server::make($api.'/v1')->setDescription('API host')];

                $openApi->secure(
                    SecurityScheme::apiKey('cookie', (string) config('session.cookie'))
                        ->as('session')
                        ->setDescription('Web app session (Sanctum). Call `GET /sanctum/csrf-cookie`, then `POST /v1/auth/login` with workspace, email and password; the browser then sends the session cookie on every request. Send the `XSRF-TOKEN` cookie value as the `X-XSRF-TOKEN` header on POST, PUT, PATCH and DELETE. See the introduction for the full steps.'),
                );
                // API clients: OAuth 2.0 client credentials (docs/07-api/authentication.md §3).
                $openApi->secure(
                    SecurityScheme::oauth2()
                        ->flow('clientCredentials', function (OAuthFlow $flow) use ($api): void {
                            $flow->tokenUrl($api.'/oauth/token');

                            foreach (ScopeMap::DESCRIPTIONS as $scope => $description) {
                                $flow->addScope($scope, $description);
                            }
                        })
                        ->as('oauth2')
                        ->setDescription('API client. Exchange the client id and secret for an access token at `POST /oauth/token` (client credentials, valid 1 hour), then send `Authorization: Bearer <access_token>` on every request. The id and secret never go to `/v1`. Only endpoints marked open to API clients accept the token, within its scopes. See the introduction for the full steps.'),
                );

                $problem = $openApi->components->addSchema(self::PROBLEM_SCHEMA, Schema::fromType(self::problemDetailsType()));
                self::useProblemDetailsInSharedResponses($openApi, $problem);
                $unauthenticated = self::addUnauthenticatedResponse($openApi, $problem);

                foreach ($openApi->paths as $path) {
                    foreach ($path->operations as $operation) {
                        // System routes (ping) are closures in routes/api.php; module routes are tagged by controller.
                        if ($operation->getAttribute('isClosure') === true && $operation->tags === []) {
                            $operation->setTags(['System']);
                        }
                        self::useProblemDetails($operation, $problem, $unauthenticated);
                    }
                }

                $openApi->addPath(self::tokenEndpoint($api, $problem));
                self::describeSchemas($openApi);
            });
    }

    /**
     * Scramble's shared error responses (validation, authorization, not found) carry Laravel's
     * `{message, errors}`; ours are problem details.
     */
    private static function useProblemDetailsInSharedResponses(OpenApi $openApi, Reference $problem): void
    {
        foreach ($openApi->components->responses as $name => $response) {
            $openApi->components->responses[$name] = Response::make($response->code)
                ->setDescription($response->description !== '' ? $response->description : 'Problem details')
                ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));
        }
    }

    private static function addUnauthenticatedResponse(OpenApi $openApi, Reference $problem): Reference
    {
        $openApi->components->responses['Unauthenticated'] = Response::make(401)
            ->setDescription('`unauthenticated`: no session or token, an expired session, or a revoked API client.')
            ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));

        return new Reference('responses', 'Unauthenticated', $openApi->components);
    }

    /**
     * Replaces inferred error bodies (Laravel's `{message, errors}`) with problem details, adds 401 to
     * authenticated operations and a default error response to every operation.
     */
    private static function useProblemDetails(Operation $operation, Reference $problem, Reference $unauthenticated): void
    {
        $responses = [];
        $has401 = false;
        foreach ($operation->responses ?? [] as $response) {
            $code = $response instanceof Response ? $response->code : null;
            if (is_int($code) && $code >= 400 || is_string($code) && ctype_digit($code) && (int) $code >= 400) {
                $response = Response::make((int) $code)
                    ->setDescription($response->description !== '' ? $response->description : 'Problem details')
                    ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));
            }
            $has401 = $has401 || (int) $code === 401 || $response instanceof Reference && $response->fullName === 'AuthenticationException';
            $responses[] = $response;
        }

        if (! $has401 && $operation->security !== []) {
            $responses[] = $unauthenticated;
        }

        $responses[] = Response::make('default')
            ->setDescription('Problem details (RFC 9457)')
            ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));

        $operation->responses = $responses;
    }

    /**
     * `POST /oauth/token` is Passport's PSR-7 endpoint behind our AccessTokenController, which Scramble
     * cannot read; it lives outside `/v1`, so the path carries its own server (authentication.md §3).
     */
    private static function tokenEndpoint(string $api, Reference $problem): Path
    {
        $string = fn (string $description): StringType => (new StringType)->setDescription($description);

        $body = new ObjectType;
        $body->addProperty('grant_type', $string('Only `client_credentials` is supported.')->enum(['client_credentials']));
        $body->addProperty('client_id', $string('The client id shown when the API client was created.')->format('uuid'));
        $body->addProperty('client_secret', $string('The secret shown once when the API client was created.'));
        $body->addProperty('scope', $string('Space-separated scopes; omitted means every scope the client was granted.')->example('tickets:read tickets:write'));
        $body->setRequired(['grant_type', 'client_id', 'client_secret']);

        $token = new ObjectType;
        $token->addProperty('token_type', $string('Always `Bearer`.')->enum(['Bearer']));
        $token->addProperty('expires_in', (new IntegerType)->setDescription('Lifetime in seconds (3600).')->example(3600));
        $token->addProperty('access_token', $string('JWT to send as `Authorization: Bearer …` to `/v1` routes open to API clients.'));
        $token->setRequired(['token_type', 'expires_in', 'access_token']);

        $error = fn (int $status, string $description): Response => Response::make($status)
            ->setDescription($description)
            ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));

        $operation = Operation::make('post')
            ->setOperationId('oauth.token')
            ->setTags(['API clients'])
            ->summary('Request an access token')
            ->description('OAuth 2.0 client-credentials grant for API clients (docs/07-api/authentication.md §3). Tokens are bound to the client\'s workspace, last one hour and are not refreshed: request a new one. Rate limited to 10 requests a minute per client. Errors are problem details that also carry the RFC 6749 `error` member.')
            ->addRequestBodyObject(
                RequestBodyObject::make()
                    ->setContent('application/x-www-form-urlencoded', Schema::fromType($body))
                    ->required(true),
            )
            ->addResponse(Response::make(200)->setDescription('Access token')->setContent('application/json', Schema::fromType($token)))
            ->addResponse($error(400, '`invalid_request`, `unsupported_grant_type` or `invalid_scope` (an unknown scope or one the client was not granted).'))
            ->addResponse($error(401, '`invalid_client`: unknown client, wrong secret, revoked client or suspended workspace.'))
            ->addResponse($error(429, '`too_many_requests`: more than 10 token requests a minute for this client.'));
        $operation->security = [];

        return Path::make('oauth/token')->servers([Server::make($api)->setDescription('API host (outside /v1)')])->addOperation($operation);
    }

    /**
     * Resource schemas get their class summary as description, and conventional top-level fields their
     * format: `*_at` fields are ISO-8601 instants, `id` and `*_id` fields UUID keys (every application
     * table is keyed by UUID v7, and webhook event ids are UUIDs too).
     */
    private static function describeSchemas(OpenApi $openApi): void
    {
        $resources = self::resourceClasses();

        foreach ($openApi->components->schemas as $name => $schema) {
            // Resource schemas are keyed by short class name; form requests by full class name.
            $class = $resources[$name] ?? null;
            if ($class === null) {
                continue;
            }

            $type = $schema->type;
            $summary = self::classSummary($class);
            if ($summary !== null && $type->description === '') {
                $type->setDescription($summary);
            }

            if ($type instanceof ObjectType) {
                self::addFormats($type);
            }
        }
    }

    /**
     * @return array<string, class-string<JsonResource>> short name => class
     */
    private static function resourceClasses(): array
    {
        $classes = [];
        $files = [...glob(app_path('Modules/*/Http/Resources/*.php')) ?: [], ...glob(app_path('Support/Http/*/*Resource.php')) ?: []];

        foreach ($files as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen(app_path()) + 1));
            if (class_exists($class) && is_subclass_of($class, JsonResource::class)) {
                $classes[class_basename($class)] = $class;
            }
        }

        return $classes;
    }

    private static function addFormats(ObjectType $type): void
    {
        foreach ($type->properties as $property => $schema) {
            if (! $schema instanceof StringType || $schema->format !== '') {
                continue;
            }

            if (str_ends_with($property, '_at')) {
                $schema->format('date-time');
            } elseif ($property === 'id' || (str_ends_with($property, '_id') && $property !== 'request_id')) {
                // `request_id` is the X-Request-Id header value, not a record key.
                $schema->format('uuid');
            }
        }
    }

    /** First paragraph of a class docblock, without tags. */
    private static function classSummary(string $class): ?string
    {
        $doc = (new ReflectionClass($class))->getDocComment();
        if ($doc === false) {
            return null;
        }

        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $line = trim(preg_replace('#^\s*/?\*+/?#', '', $line) ?? '');
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '' && $lines !== []) {
                break;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode(' ', $lines);
    }

    private static function problemDetailsType(): ObjectType
    {
        $string = fn (string $description): StringType => (new StringType)->setDescription($description);

        $fieldErrors = (new ObjectType)
            ->additionalProperties((new ArrayType)->setItems(new StringType))
            ->setDescription('Validation messages per field (422 only).');

        $meta = (new ObjectType)
            ->additionalProperties(new MixedType)
            ->setDescription('Domain-specific context, for example allowed transitions.');

        $type = new ObjectType;
        $type->addProperty('type', $string('URI of the error page on the docs host.')->format('uri'));
        $type->addProperty('title', $string('Short, stable summary of the error code.'));
        $type->addProperty('status', (new IntegerType)->setDescription('HTTP status code.'));
        $type->addProperty('detail', $string('User-safe explanation; only for domain, validation, 403 and 419 errors.'));
        $type->addProperty('code', $string('Stable machine-readable error code (App\\Support\\Errors\\ErrorCode).'));
        $type->addProperty('instance', $string('Request path.'));
        $type->addProperty('request_id', $string('Matches the X-Request-Id response header and the log lines.'));
        $type->addProperty('errors', $fieldErrors);
        $type->addProperty('meta', $meta);
        $type->setRequired(['type', 'title', 'status', 'code', 'instance']);
        $type->setDescription('RFC 9457 problem details.');
        $type->examples([[
            'type' => 'https://'.config('helpdesk.hosts.docs').'/errors/validation_failed',
            'title' => 'Validation failed',
            'status' => 422,
            'detail' => 'The title field is required.',
            'code' => 'validation_failed',
            'instance' => '/v1/tickets',
            'request_id' => '6f1c2d4e-8a3b-4c5d-9e7f-0a1b2c3d4e5f',
            'errors' => ['title' => ['The title field is required.']],
        ]]);

        return $type;
    }
}
