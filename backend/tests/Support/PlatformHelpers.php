<?php

declare(strict_types=1);

use App\Modules\Platform\Models\PlatformUser;

/** A URL of the platform API on the admin host. */
function onPlatform(string $path): string
{
    return 'https://'.config('helpdesk.hosts.admin').'/platform-api/'.ltrim($path, '/');
}

function actingAsPlatformAdmin(): PlatformUser
{
    // Reloaded, as the session guard would: a fresh model lacks the columns filled by database defaults.
    $admin = PlatformUser::query()->firstOrCreate(
        ['email' => 'admin@platform.test'],
        ['name' => 'Platform admin', 'password' => 'platform-password'],
    )->fresh() ?? throw new RuntimeException('The platform admin was not stored.');

    test()->actingAs($admin, 'platform');

    return $admin;
}
