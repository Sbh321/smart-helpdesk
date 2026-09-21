<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Integrations\Exceptions\OAuthError;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /oauth/token` on the api host: the OAuth 2.0 client-credentials grant
 * (docs/07-api/authentication.md §3). Every other grant is refused before Passport sees it.
 * Failures are problem details that also carry the RFC 6749 `error` member.
 */
final class AccessTokenController
{
    use ConvertsPsrResponses;

    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): Response
    {
        $body = $request->getParsedBody();
        $grant = is_array($body) ? ($body['grant_type'] ?? null) : null;

        try {
            if ($grant !== 'client_credentials') {
                throw OAuthServerException::unsupportedGrantType();
            }

            return $this->convertResponse($this->server->respondToAccessTokenRequest($request, $response));
        } catch (OAuthServerException $e) {
            throw OAuthError::from($e);
        }
    }
}
