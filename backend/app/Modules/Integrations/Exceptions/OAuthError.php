<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * A token-endpoint failure as problem details that still carries the RFC 6749 §5.2 members
 * `error` and `error_description`, so standard OAuth client libraries read it as well.
 */
final class OAuthError extends DomainException
{
    private function __construct(string $detail, private readonly ErrorCode $errorCode, private readonly string $oauthError)
    {
        parent::__construct($detail);
    }

    public static function from(OAuthServerException $e): self
    {
        $code = match ($e->getErrorType()) {
            'invalid_client', 'unauthorized_client' => ErrorCode::InvalidClient,
            'invalid_scope' => ErrorCode::InvalidScope,
            'unsupported_grant_type' => ErrorCode::UnsupportedGrantType,
            default => ErrorCode::BadRequest,
        };

        return new self($e->getMessage(), $code, $e->getErrorType());
    }

    public function code(): ErrorCode
    {
        return $this->errorCode;
    }

    public function extensions(): array
    {
        return ['error' => $this->oauthError, 'error_description' => $this->getMessage()];
    }
}
