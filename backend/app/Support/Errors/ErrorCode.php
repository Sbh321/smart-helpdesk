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
    case AlreadyAssigned = 'already_assigned';
    case InUse = 'in_use';
    case AlreadyDecided = 'already_decided';
    case InvalidTransition = 'invalid_transition';
    case NoEligibleAgent = 'no_eligible_agent';
    case DuplicateTargetInvalid = 'duplicate_target_invalid';
    case LastOwner = 'last_owner';
    case ResolutionCommentRequired = 'resolution_comment_required';
    case SlaTargetMissing = 'sla_target_missing';
    case SettingsInvalid = 'settings_invalid';
    case PayloadTooLarge = 'payload_too_large';
    case RateLimited = 'rate_limited';
    case InternalError = 'internal_error';
    case StorageUnavailable = 'storage_unavailable';
    case QuotaExceeded = 'quota_exceeded';
    case ServiceUnavailable = 'service_unavailable';
    case InvalidClient = 'invalid_client';
    case InvalidScope = 'invalid_scope';
    case UnsupportedGrantType = 'unsupported_grant_type';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case DeliveryNotRetryable = 'delivery_not_retryable';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest, self::InvalidScope, self::UnsupportedGrantType => 400,
            self::InvalidCredentials, self::Unauthenticated, self::InvalidClient => 401,
            self::Forbidden, self::TenantSuspended, self::AccountLocked => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict, self::StaleUpdate, self::AlreadyAssigned, self::InUse, self::AlreadyDecided,
            self::DeliveryNotRetryable => 409,
            self::PayloadTooLarge => 413,
            self::SessionExpired => 419,
            self::ValidationFailed, self::InvalidTransition, self::NoEligibleAgent, self::QuotaExceeded,
            self::DuplicateTargetInvalid, self::LastOwner, self::ResolutionCommentRequired, self::SlaTargetMissing,
            self::SettingsInvalid, self::IdempotencyKeyReused => 422,
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
            self::AlreadyAssigned => 'The ticket is already assigned',
            self::InUse => 'The record is still in use',
            self::AlreadyDecided => 'The suggestion was already decided',
            self::InvalidTransition => 'Invalid status transition',
            self::NoEligibleAgent => 'No eligible agent',
            self::DuplicateTargetInvalid => 'Invalid duplicate target',
            self::LastOwner => 'The last owner cannot be removed',
            self::ResolutionCommentRequired => 'A resolution comment is required',
            self::SlaTargetMissing => 'The SLA policy has no target for this priority',
            self::SettingsInvalid => 'The settings are not valid',
            self::PayloadTooLarge => 'Payload too large',
            self::RateLimited => 'Too many requests',
            self::InternalError => 'Something went wrong',
            self::StorageUnavailable => 'File storage is unavailable',
            self::QuotaExceeded => 'Storage quota exceeded',
            self::ServiceUnavailable => 'Service unavailable',
            self::InvalidClient => 'Client authentication failed',
            self::InvalidScope => 'The requested scope is not allowed',
            self::UnsupportedGrantType => 'Unsupported grant type',
            self::IdempotencyKeyReused => 'The idempotency key was used for a different request',
            self::DeliveryNotRetryable => 'The delivery cannot be retried',
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
