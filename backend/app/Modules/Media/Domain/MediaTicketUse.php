<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain;

final readonly class MediaTicketUse
{
    public function __construct(public int $number) {}
}
