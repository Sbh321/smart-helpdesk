<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string|null $invited_by_user_id
 * @property string $token_hash
 * @property list<string> $role_names
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 */
final class Invitation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id'];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public function isPending(Carbon|DateTimeInterface $now): bool
    {
        return $this->accepted_at === null && $this->expires_at->greaterThan($now);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'role_names' => 'array',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
