<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Integrations\Domain\ScopeMap;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * OpenAPI document setup (ADR-0010, docs/07-api/documentation.md).
 *
 * Adds the server, the security schemes and the shared RFC 9457 problem-details schema, and rewrites every
 * error response to that schema so generated clients get one error type (docs/03-architecture/error-handling.md).
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public const PROBLEM_SCHEMA = 'ProblemDetails';

    public const PROBLEM_CONTENT_TYPE = 'application/problem+json';

    public function boot(): void
    {
        // Public reference: it describes the API only and contains no tenant data. Guests are allowed.
        Gate::define('viewApiDocs', fn (?Authenticatable $user = null): bool => true);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->servers = [
                Server::make('https://'.config('helpdesk.hosts.api').'/v1')->setDescription('API host'),
            ];

            $openApi->secure(
                SecurityScheme::apiKey('cookie', (string) config('session.cookie'))
                    ->as('session')
                    ->setDescription('SPA session (Sanctum). Call `GET /sanctum/csrf-cookie` first and send the `XSRF-TOKEN` cookie value as `X-XSRF-TOKEN` on unsafe methods.'),
            );
            // API clients: OAuth 2.0 client credentials (docs/07-api/authentication.md §3).
            $openApi->secure(
                SecurityScheme::oauth2()
                    ->flow('clientCredentials', function (OAuthFlow $flow): void {
                        $flow->tokenUrl('https://'.config('helpdesk.hosts.api').'/oauth/token');

                        foreach (ScopeMap::DESCRIPTIONS as $scope => $description) {
                            $flow->addScope($scope, $description);
                        }
                    })
                    ->as('oauth2')
                    ->setDescription('API client access token from `POST /oauth/token` (client credentials, 1 hour). Only endpoints open to API clients accept it.'),
            );

            $problem = $openApi->components->addSchema(self::PROBLEM_SCHEMA, Schema::fromType(self::problemDetailsType()));

            foreach ($openApi->paths as $path) {
                foreach ($path->operations as $operation) {
                    // System routes (ping) are closures in routes/api.php; module routes are tagged by controller.
                    if ($operation->getAttribute('isClosure') === true && $operation->tags === []) {
                        $operation->setTags(['System']);
                    }
                    self::useProblemDetails($operation, $problem);
                }
            }
        });
    }

    /** Replaces inferred error bodies (Laravel's `{message, errors}`) with problem details and adds a default error. */
    private static function useProblemDetails(Operation $operation, Reference $problem): void
    {
        $responses = [];
        foreach ($operation->responses ?? [] as $response) {
            $code = $response instanceof Response ? $response->code : null;
            if (is_int($code) && $code >= 400 || is_string($code) && ctype_digit($code) && (int) $code >= 400) {
                $response = Response::make((int) $code)
                    ->setDescription($response->description !== '' ? $response->description : 'Problem details')
                    ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));
            }
            $responses[] = $response;
        }

        $responses[] = Response::make('default')
            ->setDescription('Problem details (RFC 9457)')
            ->setContent(self::PROBLEM_CONTENT_TYPE, Schema::fromType($problem));

        $operation->responses = $responses;
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

        return $type;
    }
}
