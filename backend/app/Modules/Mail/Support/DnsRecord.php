<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/**
 * One DNS record the operator publishes for the mail domain. `ready` is false when a value is still
 * unknown (the DKIM key before mail-init.sh has run); `value` then says what to do.
 */
final readonly class DnsRecord
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public string $purpose,
        public bool $ready = true,
    ) {}
}
