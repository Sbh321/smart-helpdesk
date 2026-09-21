<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * A webhook URL the SSRF guard refuses (docs/07-api/webhooks.md §SSRF protection): 422
 * `webhook_url_rejected` with `meta.reason` and a field error on `url`. At send time the same
 * reason is stored on the delivery as `url_rejected`.
 */
final class WebhookUrlRejected extends DomainException
{
    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail, ['reason' => $reason]);
    }

    public static function because(string $reason): self
    {
        return new self($reason, match ($reason) {
            'invalid_url' => 'The webhook URL is not a valid absolute URL.',
            'scheme' => 'The webhook URL must use https.',
            'userinfo' => 'The webhook URL must not contain a user name or password.',
            'port' => 'The webhook URL uses a port that is not allowed.',
            'platform_host' => 'The webhook URL points at this helpdesk itself.',
            'unresolvable' => 'The webhook host name does not resolve.',
            'private_address' => 'The webhook host resolves to a private, loopback or reserved address.',
            default => 'The webhook URL is not allowed.',
        });
    }

    public function code(): ErrorCode
    {
        return ErrorCode::WebhookUrlRejected;
    }

    public function errors(): array
    {
        return ['url' => [$this->getMessage()]];
    }
}
