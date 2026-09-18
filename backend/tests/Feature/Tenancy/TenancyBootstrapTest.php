<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<string, mixed> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen = [
            'tenant' => tenant()?->getTenantKey(),
            'context' => Context::get('tenant_id'),
            'setting' => (string) DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value,
        ];
    }
}

function currentTenantSetting(): string
{
    return sessionSetting('app.current_tenant');
}

/**
 * One `app.*` PostgreSQL session setting; '' when it was never set or has been reset.
 */
function sessionSetting(string $name): string
{
    return (string) DB::selectOne('SELECT current_setting(?, true) AS value', [$name])->value;
}

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
});

it('sets and resets app.current_tenant, visible through SHOW', function (): void {
    tenancy()->initialize($this->acme);
    expect(currentTenantSetting())->toBe($this->acme->id);

    tenancy()->initialize($this->globex);
    expect(currentTenantSetting())->toBe($this->globex->id);

    tenancy()->end();
    expect(currentTenantSetting())->toBe('');
});

describe('actor settings for change capture', function (): void {
    it('sets system when no user is authenticated, and resets everything with the tenant', function (): void {
        Context::add('request_id', 'req-01HZ');
        tenancy()->initialize($this->acme);

        expect(sessionSetting('app.actor_type'))->toBe('system')
            ->and(sessionSetting('app.actor_id'))->toBe('')
            ->and(sessionSetting('app.request_id'))->toBe('req-01HZ');

        tenancy()->end();

        expect(sessionSetting('app.actor_type'))->toBe('')
            ->and(sessionSetting('app.request_id'))->toBe('');
    });

    it('sets the authenticated user as the actor', function (): void {
        $user = createTenantUser($this->acme);
        actingAsTenantUser($this->acme, $user);

        tenancy()->initialize($this->acme);

        expect(sessionSetting('app.actor_type'))->toBe('user')
            ->and(sessionSetting('app.actor_id'))->toBe($user->id);
    });

    it('re-reads the actor when a guard authenticates after tenancy was initialised', function (): void {
        tenancy()->initialize($this->acme);
        $user = createTenantUser($this->acme);
        expect(sessionSetting('app.actor_type'))->toBe('system');

        // The tenant middleware group runs auth:sanctum after tenancy is initialised.
        Auth::guard('web')->setUser($user);

        expect(sessionSetting('app.actor_type'))->toBe('user')
            ->and(sessionSetting('app.actor_id'))->toBe($user->id);

        tenancy()->end();
    });
});

it('re-applies the setting to a connection that lost it, as after a reconnect', function (): void {
    tenancy()->initialize($this->acme);

    // A reconnect starts a fresh PostgreSQL session, which has no settings.
    DB::connection()->statement('RESET app.current_tenant');
    expect(currentTenantSetting())->toBe('');

    app(RlsTenancyBootstrapper::class)->reapply(DB::connection());

    expect(currentTenantSetting())->toBe($this->acme->id);
    tenancy()->end();
});

it('listens for new connections', function (): void {
    expect(Event::hasListeners(ConnectionEstablished::class))->toBeTrue();
});

it('follows the tenant with the permission team and the log context', function (): void {
    $registrar = app(PermissionRegistrar::class);

    tenancy()->initialize($this->acme);
    expect($registrar->getPermissionsTeamId())->toBe($this->acme->id)
        ->and(Context::get('tenant_id'))->toBe($this->acme->id);

    tenancy()->end();
    expect($registrar->getPermissionsTeamId())->toBeNull()
        ->and(Context::has('tenant_id'))->toBeFalse();
});

it('scopes storage keys under tenants/{id}/', function (): void {
    tenancy()->initialize($this->acme);

    Storage::disk('local')->put('probe.txt', 'x');

    expect(Storage::disk('local')->path('probe.txt'))->toContain("/tenants/{$this->acme->id}/probe.txt")
        ->and(config('filesystems.disks.s3.root'))->toBe("tenants/{$this->acme->id}")
        ->and(config('filesystems.disks.s3-presign.root'))->toBe("tenants/{$this->acme->id}");

    Storage::disk('local')->delete('probe.txt');
    tenancy()->end();
    expect(config('filesystems.disks.s3.root'))->toBeNull();
});

it('restores the tenant in the queue worker for jobs dispatched inside a tenant', function (): void {
    config(['queue.default' => 'database']);
    RecordTenantJob::$seen = [];

    tenancy()->initialize($this->acme);
    RecordTenantJob::dispatch();
    tenancy()->end();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--memory' => 2048]);

    expect(RecordTenantJob::$seen)->toBe([
        'tenant' => $this->acme->id,
        'context' => $this->acme->id,
        'setting' => $this->acme->id,
    ])->and(tenancy()->initialized)->toBeFalse();
});

it('runs central jobs without a tenant', function (): void {
    config(['queue.default' => 'database']);
    RecordTenantJob::$seen = [];

    RecordTenantJob::dispatch();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true, '--memory' => 2048]);

    expect(RecordTenantJob::$seen['tenant'])->toBeNull()
        ->and(RecordTenantJob::$seen['setting'])->toBe('');
});

it('runs a command per tenant with tenants:run', function (): void {
    Artisan::command('test:tenant-slug', fn () => $this->line('slug='.tenant('slug')));

    Artisan::call('tenants:run', ['commandname' => 'test:tenant-slug', '--tenants' => [$this->acme->id, $this->globex->id]]);

    expect(Artisan::output())->toContain('slug=acme')->toContain('slug=globex');
});

describe('BelongsToTenant', function (): void {
    it('fills tenant_id on create and filters queries by the current tenant', function (): void {
        tenancy()->initialize($this->acme);
        $mine = User::factory()->create();
        tenancy()->end();
        User::factory()->forTenant($this->globex)->create();

        tenancy()->initialize($this->acme);
        expect($mine->tenant_id)->toBe($this->acme->id)
            ->and(User::query()->pluck('id')->all())->toBe([$mine->id]);
        tenancy()->end();
    });

    it('refuses to create a user outside a tenant without a tenant_id', function (): void {
        User::factory()->create();
    })->throws(QueryException::class, 'tenant_id');

    it('never changes tenant_id through the model', function (): void {
        $user = createTenantUser($this->acme);

        $user->update(['tenant_id' => $this->globex->id]);
    })->throws(LogicException::class, 'tenant_id cannot change');

    it('never changes tenant_id in the database either', function (): void {
        $user = createTenantUser($this->acme);

        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->globex->id]);
    })->throws(QueryException::class, 'tenant_id is immutable');

    it('keeps email unique per tenant, ignoring case', function (): void {
        createTenantUser($this->acme, ['email' => 'priya@acme.test']);
        createTenantUser($this->globex, ['email' => 'priya@acme.test']);

        expect(fn () => createTenantUser($this->acme, ['email' => 'Priya@Acme.test']))
            ->toThrow(QueryException::class, 'users_tenant_email_unique');
    });
});

it('gives tenants UUID v7 keys', function (): void {
    expect($this->acme->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('enforces the slug format and the reserved list in the database', function (string $slug): void {
    DB::table('tenants')->insert(['id' => (string) Str::uuid7(), 'slug' => $slug, 'name' => 'X']);
})->throws(QueryException::class, 'tenants_slug_')->with(['api', 'monitor', 'Acme', '-acme', 'ac--me', 'acme-']);
