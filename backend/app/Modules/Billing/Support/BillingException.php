<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/** A billing rule the API reports as problem details (ADR-0025). */
final class BillingException extends DomainException
{
    /**
     * @param  array<string, list<string>>  $fieldErrors
     */
    public function __construct(private readonly ErrorCode $errorCode, string $detail, private readonly array $fieldErrors = [])
    {
        parent::__construct($detail);
    }

    public static function field(string $field, string $message): self
    {
        return new self(ErrorCode::ValidationFailed, $message, [$field => [$message]]);
    }

    public function code(): ErrorCode
    {
        return $this->errorCode;
    }

    public function errors(): array
    {
        return $this->fieldErrors;
    }
}
