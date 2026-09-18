<?php

declare(strict_types=1);

namespace App\Support\Errors;

/**
 * Stable machine codes for problem-details responses (docs/03-architecture/error-handling.md).
 * Clients branch on these values, so existing cases are never renamed.
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';
    case ValidationFailed = 'validation_failed';
    case InvalidCredentials = 'invalid_credentials';
    case Unauthenticated = 'unauthenticated';
    case SessionExpired = 'session_expired';
    case Forbidden = 'forbidden';
    case TenantSuspended = 'tenant_suspended';
    case AccountLocked = 'account_locked';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case StaleUpdate = 'stale_update';
    case InvalidTransition = 'invalid_transition';
    case NoEligibleAgent = 'no_eligible_agent';
    case DuplicateTargetInvalid = 'duplicate_target_invalid';
    case LastOwner = 'last_owner';
    case PayloadTooLarge = 'payload_too_large';
    case RateLimited = 'rate_limited';
    case InternalError = 'internal_error';
    case StorageUnavailable = 'storage_unavailable';
    case ServiceUnavailable = 'service_unavailable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::InvalidCredentials, self::Unauthenticated => 401,
            self::Forbidden, self::TenantSuspended, self::AccountLocked => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::StaleUpdate => 409,
            self::PayloadTooLarge => 413,
            self::SessionExpired => 419,
            self::ValidationFailed, self::InvalidTransition, self::NoEligibleAgent,
            self::DuplicateTargetInvalid, self::LastOwner => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::StorageUnavailable => 502,
            self::ServiceUnavailable => 503,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::BadRequest => 'Bad request',
            self::ValidationFailed => 'The given data was invalid',
            self::InvalidCredentials => 'Invalid credentials',
            self::Unauthenticated => 'Unauthenticated',
            self::SessionExpired => 'Session expired',
            self::Forbidden => 'Forbidden',
            self::TenantSuspended => 'Workspace suspended',
            self::AccountLocked => 'Account temporarily locked',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::Conflict => 'Conflict',
            self::StaleUpdate => 'The record was changed by someone else',
            self::InvalidTransition => 'Invalid status transition',
            self::NoEligibleAgent => 'No eligible agent',
            self::DuplicateTargetInvalid => 'Invalid duplicate target',
            self::LastOwner => 'The last owner cannot be removed',
            self::PayloadTooLarge => 'Payload too large',
            self::RateLimited => 'Too many requests',
            self::InternalError => 'Something went wrong',
            self::StorageUnavailable => 'File storage is unavailable',
            self::ServiceUnavailable => 'Service unavailable',
        };
    }

    public static function forStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            413 => self::PayloadTooLarge,
            419 => self::SessionExpired,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            502 => self::StorageUnavailable,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::InternalError : self::BadRequest,
        };
    }
}
