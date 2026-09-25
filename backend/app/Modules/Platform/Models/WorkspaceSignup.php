<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A self sign-up waiting for its email link (ADR-0025 §8). Control plane; `tenant_id` is set once the
 * workspace exists.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password hashed
 * @property string $workspace_name
 * @property string $slug
 * @property string $timezone
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $verified_at
 * @property string|null $tenant_id
 */
final class WorkspaceSignup extends Model
{
    use HasUuids;

    public const HOURS = 24;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'token_hash'];

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
