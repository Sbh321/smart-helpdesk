<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings;

/**
 * A section whose values live in their own columns (the workspace name and time zone are on `tenants`).
 * `defaults()` reads the current values; `persist()` writes them. The write still bumps the settings
 * version and is audited like any other section.
 */
interface StoresOutsideSettings
{
    /** @param array<string, mixed> $values */
    public function persist(array $values): void;
}
