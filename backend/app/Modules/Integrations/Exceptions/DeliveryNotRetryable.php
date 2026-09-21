<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * `POST /v1/webhook-deliveries/{delivery}/retry` on a delivery that is not `failed` or `dead`, whose
 * subscription is disabled, or that used up its manual retries (409 `delivery_not_retryable`,
 * `meta.reason`).
 */
final class DeliveryNotRetryable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self(match ($reason) {
            'state' => 'Only failed or dead deliveries can be retried.',
            'subscription_disabled' => 'Enable the webhook before retrying its deliveries.',
            'manual_retries_exhausted' => 'This delivery has used all of its manual retries.',
            default => 'This delivery cannot be retried.',
        }, ['reason' => $reason]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::DeliveryNotRetryable;
    }
}
