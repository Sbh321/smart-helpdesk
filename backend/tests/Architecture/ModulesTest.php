<?php

declare(strict_types=1);
use App\Support\Modules\ModuleServiceProvider;

// Module boundaries (docs/03-architecture/backend.md §Dependency rules, docs/10-quality/code-quality.md).

arch('code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('env() is only called in config files')
    ->expect('env')
    ->not->toBeUsed()
    ->ignoring('config');

arch('domain and strategy classes stay framework-free')
    ->expect(['App\Modules\Automation\Strategies', 'App\Modules\Sla\Strategies', 'App\Modules\Reporting\Domain'])
    ->not->toUse(['Illuminate\Database', 'Illuminate\Http', 'Illuminate\Support\Facades']);

arch('contacts do not depend on tickets or automation')
    ->expect('App\Modules\Contacts')
    ->not->toUse(['App\Modules\Tickets', 'App\Modules\Automation', 'App\Modules\Sla']);

arch('tickets depend only on contacts, agents and media among domain modules')
    ->expect('App\Modules\Tickets')
    ->not->toUse([
        'App\Modules\Automation', 'App\Modules\Sla', 'App\Modules\Integrations', 'App\Modules\Reporting',
        'App\Modules\Mail', 'App\Modules\Notifications',
    ]);

// Automation and Sla hook into tickets through the synchronous events in Tickets\Events; the
// arrows never point the other way (docs/03-architecture/backend.md §Dependency rules).
arch('agents do not depend on tickets, automation or sla')
    ->expect('App\Modules\Agents')
    ->not->toUse(['App\Modules\Tickets', 'App\Modules\Automation', 'App\Modules\Sla']);

arch('sla depends on tickets but never on automation')
    ->expect('App\Modules\Sla')
    ->not->toUse(['App\Modules\Automation']);

arch('media depends on tenancy only among domain modules')
    ->expect('App\Modules\Media')
    ->not->toUse(['App\Modules\Tickets', 'App\Modules\Automation', 'App\Modules\Sla', 'App\Modules\Agents']);

arch('mail depends on tickets, contacts, media and the automation text helpers only (M3-19)')
    ->expect('App\Modules\Mail')
    ->not->toUse([
        'App\Modules\Sla', 'App\Modules\Agents', 'App\Modules\Integrations', 'App\Modules\Notifications',
        'App\Modules\Automation\Actions', 'App\Modules\Automation\Strategies', 'App\Modules\Automation\Contracts',
    ]);

arch('the mail domain stays framework-free')
    ->expect('App\Modules\Mail\Domain')
    ->not->toUse(['Illuminate', 'Webklex']);

arch('reporting is never imported by other modules')
    ->expect([
        'App\Modules\Platform', 'App\Modules\Tenancy', 'App\Modules\Identity', 'App\Modules\Contacts',
        'App\Modules\Agents', 'App\Modules\Tickets', 'App\Modules\Sla', 'App\Modules\Automation',
        'App\Modules\Media', 'App\Modules\Mail', 'App\Modules\Integrations', 'App\Modules\Audit',
    ])
    ->not->toUse('App\Modules\Reporting');

arch('realtime is never imported by other modules')
    ->expect([
        'App\Modules\Platform', 'App\Modules\Tenancy', 'App\Modules\Identity', 'App\Modules\Contacts',
        'App\Modules\Agents', 'App\Modules\Tickets', 'App\Modules\Sla', 'App\Modules\Automation',
        'App\Modules\Media', 'App\Modules\Mail', 'App\Modules\Integrations', 'App\Modules\Audit',
        'App\Modules\Notifications', 'App\Modules\Reporting', 'App\Models',
    ])
    ->not->toUse('App\Modules\Realtime');

arch('algorithm implementations are only referenced through their contracts')
    ->expect(['App\Modules\Automation\Strategies', 'App\Modules\Sla\Strategies'])
    ->toOnlyBeUsedIn(['App\Modules\Automation', 'App\Modules\Sla', 'App\Providers']);

it('gives every module a provider that extends the module base provider and is registered', function (): void {
    $registered = require __DIR__.'/../../bootstrap/providers.php';

    foreach (glob(__DIR__.'/../../app/Modules/*', GLOB_ONLYDIR) as $directory) {
        $module = basename($directory);
        $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

        expect(class_exists($provider))->toBeTrue("{$module} has no {$module}ServiceProvider")
            ->and(is_subclass_of($provider, ModuleServiceProvider::class))->toBeTrue()
            ->and($registered)->toContain($provider);
    }
});

// Billing announces what happened (PaymentSubmitted) and Platform tells the admins; provisioning in
// Platform starts subscriptions, so the arrow points one way only (ADR-0025).
arch('billing never depends on the platform module')
    ->expect('App\Modules\Billing')
    ->not->toUse('App\Modules\Platform');
