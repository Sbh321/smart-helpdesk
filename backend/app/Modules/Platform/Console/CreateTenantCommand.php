<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\ProvisionTenant;
use Illuminate\Console\Command;

/**
 * `php artisan platform:create-tenant acme "Acme Corp" --owner=priya@acme.test`
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'platform:create-tenant
        {slug : Workspace slug used in SPA URLs}
        {name : Display name}
        {--owner= : Email address of the first owner, who is invited by mail}
        {--owner-name=Workspace owner : Name shown until the owner edits it}
        {--timezone=UTC : IANA time zone}';

    protected $description = 'Provision a workspace with its counter, domain and owner invitation';

    public function handle(ProvisionTenant $provision): int
    {
        $result = $provision(
            (string) $this->argument('slug'),
            (string) $this->argument('name'),
            $this->option('owner') === null ? null : (string) $this->option('owner'),
            (string) $this->option('owner-name'),
            (string) $this->option('timezone'),
        );

        $tenant = $result['tenant'];

        $this->components->info("Workspace {$tenant->slug} is ready ({$tenant->id}).");

        if ($result['owner'] !== null) {
            $this->components->twoColumnDetail('Owner', (string) $result['owner']->email);
        }

        if ($result['invitation_token'] !== null) {
            $this->components->twoColumnDetail('Invitation link', sprintf(
                'https://%s/%s/accept-invitation?token=%s',
                config('helpdesk.hosts.app'),
                $tenant->slug,
                $result['invitation_token'],
            ));
        }

        return self::SUCCESS;
    }
}
