<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Webhooks;

/**
 * The outcome of one HTTP attempt, before it is written to the delivery row.
 */
final readonly class DeliveryAttempt
{
    public function __construct(
        public bool $succeeded,
        public ?int $status,
        public ?string $excerpt,
        public ?string $error,
        public ?int $durationMs = null,
    ) {}

    public static function succeeded(int $status, ?string $excerpt): self
    {
        return new self(true, $status, $excerpt, null);
    }

    public static function failed(string $error, ?int $status = null, ?string $excerpt = null): self
    {
        return new self(false, $status, $excerpt, $error);
    }

    public function withDuration(int $milliseconds): self
    {
        return new self($this->succeeded, $this->status, $this->excerpt, $this->error, $milliseconds);
    }
}
