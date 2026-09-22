<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/** One message read from the inbound mailbox: where it was and its raw bytes. */
final readonly class FetchedMessage
{
    public function __construct(
        public string $folder,
        public string $uid,
        public string $raw,
    ) {}
}
