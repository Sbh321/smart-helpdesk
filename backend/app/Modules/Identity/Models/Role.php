<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A named set of permissions. `tenant_id` null marks the global default roles that every
 * workspace shares; tenant rows are custom roles (ADR-0007).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property bool $is_system
 */
final class Role extends SpatieRole
{
    use HasUuids;

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
