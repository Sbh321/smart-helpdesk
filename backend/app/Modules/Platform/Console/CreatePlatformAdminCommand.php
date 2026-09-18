<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Platform\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Bootstraps the first platform super admin: `php artisan platform:create-admin`.
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {--name= : Display name}
        {--email= : Sign-in address}
        {--password= : Password; a random one is generated and printed when omitted}';

    protected $description = 'Create a platform super admin for the admin host';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?? $this->ask('Name'));
        $email = Str::lower((string) ($this->option('email') ?? $this->ask('Email')));
        $password = (string) ($this->option('password') ?? Str::password(20));

        if (PlatformUser::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->components->error("A platform admin with {$email} already exists.");

            return self::FAILURE;
        }

        $user = PlatformUser::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        Audit::record('platform_user.created', $user, tenantId: null, actorType: ActorType::System);

        $this->components->info('Platform admin created.');
        $this->components->twoColumnDetail('Sign in at', 'https://'.config('helpdesk.hosts.admin'));
        $this->components->twoColumnDetail('Email', $email);

        if ($this->option('password') === null) {
            $this->components->twoColumnDetail('Password (shown once)', $password);
        }

        return self::SUCCESS;
    }
}
