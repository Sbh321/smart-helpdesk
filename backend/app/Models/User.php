<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who signs in to one tenant (ADR-0021: one tenant per user in the MVP).
 *
 * MVP-SHORTCUT: stays in App\Models until the Identity module takes it over; V1: none (moves into the Identity module with the users API, M2-12).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 * @property array<string, mixed> $preferences
 */
#[Fillable(['tenant_id', 'name', 'email', 'password', 'is_active', 'preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use BelongsToTenant;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;

    /**
     * One permission vocabulary for the whole application (ADR-0007): a user's roles are the same
     * whether the request arrives with a session (sanctum) or a token.
     */
    protected string $guard_name = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'preferences' => 'array',
        ];
    }
}
