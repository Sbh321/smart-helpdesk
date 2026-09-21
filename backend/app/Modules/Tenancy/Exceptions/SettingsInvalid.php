<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;

/**
 * Settings that are well-formed but break a rule across fields (docs/07-api/errors.md:
 * weights do not sum to 1, thresholds not decreasing). Rendered as 422 `settings_invalid`
 * with the same `errors` map as a validation failure, so forms mark the fields.
 */
final class SettingsInvalid extends DomainException
{
    /** @param array<string, string> $fields */
    public function __construct(string $section, private readonly array $fields)
    {
        parent::__construct('The settings are not valid together.', ['section' => $section]);
    }

    public function code(): ErrorCode
    {
        return ErrorCode::SettingsInvalid;
    }

    public function errors(): array
    {
        return array_map(fn (string $message): array => [$message], $this->fields);
    }
}
