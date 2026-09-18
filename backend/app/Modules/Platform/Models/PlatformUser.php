<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A platform super admin. Control-plane model: no tenant, no tenant routes.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $last_login_at
 */
final class PlatformUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'platform_users';

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
