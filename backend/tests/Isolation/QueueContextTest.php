<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
 * Item 6 of the mandatory isolation suite (docs/10-quality/testing.md).
 *
 * tests/Feature/Tenancy/TenancyBootstrapTest.php already proves the plumbing: a job dispatched
 * inside tenant A is handled with tenant A initialised, `app.current_tenant` set and the log
 * context restored, a central job runs with no tenant, and tenancy ends after the job. This file
 * adds what that test does not cover — that the restored context actually isolates the job's own
 * queries, that two tenants' jobs in one worker run do not bleed into each other, and that the
 * spatie permission team follows the job.
 */

final class QueriesUsersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<string, array<string, mixed>> */
    public static array $seen = [];

    public function __construct(private readonly string $label) {}

    public function handle(): void
    {
        self::$seen[$this->label] = [
            'tenant' => tenant()?->getTenantKey(),
            'setting' => (string) DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value,
            'permission_team' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
            'emails' => User::query()->orderBy('email')->pluck('email')->all(),
        ];
    }
}

function dispatchInsideTenant(Tenant $tenant, string $label): void
{
    tenancy()->initialize($tenant);
    QueriesUsersJob::dispatch($label);
    tenancy()->end();
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    QueriesUsersJob::$seen = [];

    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');

    createTenantUser($this->acme, ['email' => 'mine@acme.test']);
    createTenantUser($this->globex, ['email' => 'theirs@globex.test']);
});

it('gives each queued job only its own tenant rows, and a central job none, in one worker run', function (): void {
    dispatchInsideTenant($this->acme, 'acme');
    dispatchInsideTenant($this->globex, 'globex');
    QueriesUsersJob::dispatch('central');

    // --memory is raised because the worker inherits this test process, which is far past the
    // 128 MB default late in a full suite run and would otherwise stop after the first job.
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 2048]);

    expect(QueriesUsersJob::$seen['acme'])->toBe([
        'tenant' => $this->acme->getKey(),
        'setting' => $this->acme->getKey(),
        'permission_team' => $this->acme->getKey(),
        'emails' => ['mine@acme.test'],
    ]);

    expect(QueriesUsersJob::$seen['globex'])->toBe([
        'tenant' => $this->globex->getKey(),
        'setting' => $this->globex->getKey(),
        'permission_team' => $this->globex->getKey(),
        'emails' => ['theirs@globex.test'],
    ]);

    // A central job keeps no tenant, no database setting and no permission team, and row-level
    // security (M3-07) shows it no tenant rows at all, although the Eloquent scope is off.
    expect(QueriesUsersJob::$seen['central'])->toBe([
        'tenant' => null,
        'setting' => '',
        'permission_team' => null,
        'emails' => [],
    ]);
});

it('carries the tenant in the job payload rather than in the job constructor', function (): void {
    dispatchInsideTenant($this->acme, 'acme');

    $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['tenant_id'] ?? null)->toBe($this->acme->getKey());
});
