<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Models\User;
use App\Modules\Identity\Notifications\PasswordResetLink;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Always reports success to the caller, so the endpoint cannot be used to discover accounts
 * (docs/07-api/authentication.md §2). Tokens are hashed, tenant-scoped and valid for 60 minutes.
 */
final readonly class SendPasswordResetLink
{
    public const TOKEN_MINUTES = 60;

    public function __construct(private Clock $clock) {}

    public function __invoke(string $email): void
    {
        $tenant = tenant();
        $user = User::query()->whereRaw('lower(email) = lower(?)', [$email])->first();

        if ($tenant === null || $user === null || ! $user->is_active) {
            return;
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['tenant_id' => $tenant->getTenantKey(), 'email' => Str::lower($email)],
            ['token' => Hash::make($token), 'created_at' => $this->clock->now()],
        );

        $user->notify(new PasswordResetLink($token, (string) $tenant->slug));
    }
}
