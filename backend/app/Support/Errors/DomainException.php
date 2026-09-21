<?php

declare(strict_types=1);

namespace App\Support\Errors;

use RuntimeException;
use Throwable;

/**
 * Base for business-rule failures that the API reports as problem details.
 *
 * The message becomes the problem `detail`, so it must be safe to show to the user.
 * `meta()` adds machine-readable context (for example the allowed transitions).
 */
abstract class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(string $detail, private readonly array $meta = [], ?Throwable $previous = null)
    {
        parent::__construct($detail, 0, $previous);
    }

    abstract public function code(): ErrorCode;

    public function status(): int
    {
        return $this->code()->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * Extra top-level members of the problem document (RFC 9457 §3.2 extension members), for
     * protocols that fix their own field names, such as OAuth's `error`.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return [];
    }

    /**
     * Field errors in the shape of a validation failure, for rules a form should show on a field.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return [];
    }
}
