<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as RFC 9457 problem details (docs/03-architecture/error-handling.md).
 *
 * Technical details never reach the client. The `request_id` in the body matches the
 * `X-Request-Id` response header and the log lines for the same request.
 */
final class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    public static function appliesTo(Request $request): bool
    {
        return $request->is('v1', 'v1/*', 'platform-api', 'platform-api/*', 'oauth/*') || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException || ! self::appliesTo($request)) {
            return null;
        }

        [$code, $status, $detail, $extra, $headers] = self::describe($e);

        $body = [
            'type' => self::typeUri($code),
            'title' => $code->title(),
            'status' => $status,
            'detail' => $detail,
            'code' => $code->value,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => $request->attributes->get('request_id'),
            ...$extra,
        ];

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse(
            array_filter($body, fn (mixed $value): bool => $value !== null),
            $status,
            ['Content-Type' => self::CONTENT_TYPE, ...$headers],
        );
    }

    public static function typeUri(ErrorCode $code): string
    {
        return sprintf('https://%s/errors/%s', config('helpdesk.hosts.docs'), $code->value);
    }

    /**
     * @return array{ErrorCode, int, ?string, array<string, mixed>, array<string, string>}
     */
    private static function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof DomainException => [
                $e->code(),
                $e->status(),
                $e->getMessage(),
                [
                    ...$e->extensions(),
                    ...($e->meta() === [] ? [] : ['meta' => $e->meta()]),
                    ...($e->errors() === [] ? [] : ['errors' => $e->errors()]),
                ],
                [],
            ],
            $e instanceof ValidationException => [
                ErrorCode::ValidationFailed,
                $e->status,
                'One or more fields are invalid.',
                ['errors' => $e->errors()],
                [],
            ],
            $e instanceof AuthenticationException => [ErrorCode::Unauthenticated, 401, null, [], []],
            $e instanceof AuthorizationException => [ErrorCode::Forbidden, 403, null, [], []],
            $e instanceof ModelNotFoundException => [ErrorCode::NotFound, 404, null, [], []],
            $e instanceof TokenMismatchException => [ErrorCode::SessionExpired, 419, 'Refresh the page and try again.', [], []],
            $e instanceof ThrottleRequestsException => [
                ErrorCode::RateLimited,
                429,
                null,
                [],
                self::stringHeaders($e->getHeaders()),
            ],
            $e instanceof HttpExceptionInterface => self::describeHttp($e),
            default => [ErrorCode::InternalError, 500, null, [], []],
        };
    }

    /**
     * @return array{ErrorCode, int, ?string, array<string, mixed>, array<string, string>}
     */
    private static function describeHttp(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();
        $code = ErrorCode::forStatus($status);
        // abort(403, 'reason') messages are written by us; framework messages for 404/405 leak routes.
        $detail = $status === 403 && $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
            ? $e->getMessage()
            : null;

        return [$code, $status, $detail, [], self::stringHeaders($e->getHeaders())];
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        return array_map(fn (mixed $value): string => is_array($value) ? implode(', ', $value) : (string) $value, $headers);
    }
}
