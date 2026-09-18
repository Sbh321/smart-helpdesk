<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Tenant ids are UUID v7 like every other key (ADR-0005).
 */
final class UuidV7Generator implements UniqueIdentifierGenerator
{
    public static function generate($resource): string
    {
        return (string) Str::uuid7();
    }
}
