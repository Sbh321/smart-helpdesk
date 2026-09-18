<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * One entry of the permission catalogue (ADR-0007). Permissions are global: the catalogue is the
 * same for every workspace, and roles decide who gets what.
 *
 * @property string $id
 * @property string $name
 */
final class Permission extends SpatiePermission
{
    use HasUuids;
}
