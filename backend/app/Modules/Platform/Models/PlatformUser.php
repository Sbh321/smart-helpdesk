<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Modules\Platform\Notifications\PlatformPasswordReset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

/**
 * A platform super admin. Control-plane model: no tenant, no tenant routes.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password null until an invited admin accepts
 * @property bool $is_active
 * @property CarbonImmutable|null $deactivated_at
 * @property string|null $invited_by_id
 * @property CarbonImmutable|null $last_login_at
 * @property CarbonImmutable|null $created_at
 */
final class PlatformUser extends Authenticatable
{
    use HasUuids;
    use Notifiable;

    protected $table = 'platform_users';

    protected $guarded = ['id'];

    /** The database default, known before the first save. */
    protected $attributes = ['is_active' => true];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Admins who can sign in: not deactivated, and past their invitation.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('password');
    }

    public function canSignIn(): bool
    {
        return $this->is_active && $this->password !== null;
    }

    /** @return HasOne<PlatformInvitation, $this> */
    public function invitation(): HasOne
    {
        return $this->hasOne(PlatformInvitation::class)->whereNull('accepted_at')->orderByDesc('created_at');
    }

    /** Password reset links point at the console (ADR-0025 §7). */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(new PlatformPasswordReset($token, $this->email));
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'deactivated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
