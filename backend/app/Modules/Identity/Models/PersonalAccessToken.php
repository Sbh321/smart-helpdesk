<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Sanctum token with a UUID key, so the plain-text token (`<id>|<secret>`) never exposes an
 * incrementing id. `tenant_id` is set when the token is issued and read by the tenant resolver.
 *
 * @property string $id
 * @property string|null $tenant_id
 */
final class PersonalAccessToken extends SanctumToken
{
    use HasUuids;
}
