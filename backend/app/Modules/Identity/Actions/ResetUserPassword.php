<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Identity\Actions\SendPasswordResetLink as Link;
use App\Modules\Tenancy\Exceptions\InvalidCredentials;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Single-use token, tenant-scoped. All other sessions of the user are invalidated by cycling the
 * remember token (docs/07-api/authentication.md §2).
 */
final readonly class ResetUserPassword
{
    public function __construct(private Clock $clock) {}

    public function __invoke(string $email, string $token, string $password): User
    {
        $tenant = tenant();
        $now = $this->clock->now();

        $row = DB::table('password_reset_tokens')
            ->where('tenant_id', $tenant?->getTenantKey())
            ->where('email', Str::lower($email))
            ->first();

        $expired = $row === null || $now->diffInMinutes($row->created_at, absolute: true) > Link::TOKEN_MINUTES;

        if ($expired || ! Hash::check($token, $row->token)) {
            throw InvalidCredentials::make();
        }

        $user = User::query()->whereRaw('lower(email) = lower(?)', [$email])->firstOr(
            fn () => throw InvalidCredentials::make(),
        );

        $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
        $user->save();

        DB::table('password_reset_tokens')
            ->where('tenant_id', $tenant?->getTenantKey())
            ->where('email', Str::lower($email))
            ->delete();

        Audit::record('user.password_reset', $user);

        return $user;
    }
}
