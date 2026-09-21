<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Identity\Models\Role;
use App\Modules\Tenancy\Support\TenantTables;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TenantModelInventory;

/*
 * Items 4 (row-level security half) and 5 of the mandatory isolation suite (docs/10-quality/testing.md,
 * M3-07, docs/08-database/tenancy.md §Row-level security): every registered tenant table has ENABLE,
 * FORCE and the `tenant_isolation` policy; the runtime role cannot step around it; a raw query that
 * forgets the tenant sees only the current tenant's rows, or none outside a tenant; a row of another
 * tenant can be neither written nor reached; and the setting never outlives its tenant on a
 * connection that serves the next job.
 */

final class RlsProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @var array<string, array{setting: string, tickets: int}> */
    public static array $seen = [];

    public function __construct(private readonly string $label, private readonly bool $fail = false) {}

    public function handle(): void
    {
        self::$seen[$this->label] = [
            'setting' => (string) DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value,
            'tickets' => DB::table('tickets')->count(),
        ];

        if ($this->fail) {
            // Fails inside a transaction, the worst case for a session setting.
            DB::transaction(function (): void {
                DB::table('tickets')->insert(['id' => (string) Str::uuid7()]);
            });
        }
    }
}

/**
 * @return array{relrowsecurity: bool, relforcerowsecurity: bool}
 */
function rowSecurityOf(string $table): array
{
    $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);

    return ['relrowsecurity' => (bool) $row->relrowsecurity, 'relforcerowsecurity' => (bool) $row->relforcerowsecurity];
}

function currentTenantSettingValue(): string
{
    return (string) DB::selectOne("SELECT current_setting('app.current_tenant', true) AS value")->value;
}

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    [$this->acmeContact, $this->acmeCategory] = $this->acme->run(fn (): array => [
        Contact::factory()->create(),
        Category::factory()->create(),
    ]);
    Ticket::factory()->forTenant($this->acme)->count(3)->create();
    Ticket::factory()->forTenant($this->globex)->count(2)->create();
});

afterEach(function (): void {
    if (tenancy()->initialized) {
        tenancy()->end();
    }
});

describe('catalog', function (): void {
    it('enables and forces row-level security on every registered tenant table', function (): void {
        foreach (TenantTables::all() as $table) {
            expect(rowSecurityOf($table))->toBe(
                ['relrowsecurity' => true, 'relforcerowsecurity' => true],
                "{$table} is in TenantTables but has no forced row-level security: call TenantTables::enableRowLevelSecurity() in its migration.",
            );
        }
    });

    it('gives every registered tenant table the tenant_isolation policy on app.current_tenant', function (): void {
        foreach (TenantTables::all() as $table) {
            $policy = DB::selectOne(
                'SELECT cmd, permissive, qual, with_check FROM pg_policies WHERE schemaname = ? AND tablename = ? AND policyname = ?',
                ['public', $table, TenantTables::POLICY],
            );

            expect($policy)->not->toBeNull("{$table} has no tenant_isolation policy.")
                ->and($policy->cmd)->toBe('ALL')
                ->and($policy->permissive)->toBe('PERMISSIVE')
                ->and($policy->qual)->toContain('app.current_tenant')
                ->and($policy->with_check)->toContain('app.current_tenant');
        }
    });

    it('adds no other policy than the read-only one for the global roles', function (): void {
        $others = DB::table('pg_policies')
            ->where('schemaname', 'public')
            ->where('policyname', '<>', TenantTables::POLICY)
            ->get(['tablename', 'policyname', 'cmd'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        expect($others)->toBe([['tablename' => 'roles', 'policyname' => TenantTables::GLOBAL_ROLES_POLICY, 'cmd' => 'SELECT']]);
    });

    it('leaves the credential and control-plane tables without row-level security', function (): void {
        // They are read before a tenant is known (docs/03-architecture/tenancy.md §Tenant resolution).
        foreach ([...TenantTables::CREDENTIALS, ...TenantModelInventory::UNSCOPED_TABLES_WITH_TENANT_ID, 'tenants'] as $table) {
            expect(rowSecurityOf($table)['relrowsecurity'])->toBeFalse($table);
        }
    });

    it('never grants the runtime role TRUNCATE on a tenant table, which bypasses row-level security', function (): void {
        foreach (TenantTables::all() as $table) {
            expect((bool) DB::selectOne("SELECT has_table_privilege(current_user, ?, 'TRUNCATE') AS allowed", [$table])->allowed)
                ->toBeFalse("The runtime role may TRUNCATE {$table}.");
        }
    });
});

describe('raw-query backstop', function (): void {
    it('shows a raw query outside any tenant no tenant rows at all', function (): void {
        expect(tenancy()->initialized)->toBeFalse()
            ->and(currentTenantSettingValue())->toBe('')
            ->and(DB::table('tickets')->count())->toBe(0)
            ->and(DB::table('users')->count())->toBe(0)
            ->and(Ticket::query()->withoutTenancy()->count())->toBe(0);
    });

    it('shows a raw query inside a tenant only that tenant\'s rows', function (): void {
        tenancy()->initialize($this->acme);

        expect(DB::table('tickets')->count())->toBe(3)
            ->and(DB::table('tickets')->where('tenant_id', $this->globex->id)->count())->toBe(0)
            ->and(DB::table('tickets')->distinct()->pluck('tenant_id')->all())->toBe([$this->acme->id]);

        tenancy()->initialize($this->globex);

        expect(DB::table('tickets')->count())->toBe(2);
    });

    it('refuses to insert a row for another tenant, or any tenant row outside a tenant', function (): void {
        $row = fn (string $tenantId): array => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'number' => 999, 'title' => 'Smuggled',
            'description' => 'x', 'status' => 'open', 'impact' => 1, 'urgency' => 1, 'priority_score' => 0,
            'priority_level' => 'P4', 'created_via' => 'seed',
            'contact_id' => $this->acmeContact->id, 'category_id' => $this->acmeCategory->id,
        ];

        // Savepoints keep the test transaction usable after each refused statement.
        expect(fn () => DB::transaction(fn () => DB::table('tickets')->insert($row($this->acme->id))))
            ->toThrow(QueryException::class, 'row-level security');

        tenancy()->initialize($this->globex);
        expect(fn () => DB::transaction(fn () => DB::table('tickets')->insert($row($this->acme->id))))
            ->toThrow(QueryException::class, 'row-level security');

        tenancy()->initialize($this->acme);
        DB::table('tickets')->insert($row($this->acme->id));
        expect(DB::table('tickets')->count())->toBe(4);
    });

    it('lets a raw update or delete of another tenant\'s rows reach nothing', function (): void {
        tenancy()->initialize($this->acme);

        expect(DB::table('tickets')->where('tenant_id', $this->globex->id)->update(['title' => 'Hijacked']))->toBe(0)
            ->and(DB::table('tickets')->where('tenant_id', $this->globex->id)->delete())->toBe(0);

        expect($this->globex->run(fn (): array => DB::table('tickets')->pluck('title')->unique()->all()))
            ->not->toContain('Hijacked')
            ->and($this->globex->run(fn (): int => DB::table('tickets')->count()))->toBe(2);
    });

    it('hides every seedable tenant table\'s rows of another tenant from a raw query', function (string $model): void {
        /** @var class-string<Model> $model */
        $theirs = $model::factory()->forTenant($this->globex)->create();
        $table = TenantModelInventory::tableOf($model);
        $key = (new $model)->getKeyName();

        expect(DB::table($table)->where($key, $theirs->getKey())->count())->toBe(0);

        tenancy()->initialize($this->acme);
        expect(DB::table($table)->where($key, $theirs->getKey())->count())->toBe(0)
            ->and(DB::table($table)->where('tenant_id', $this->globex->id)->count())->toBe(0);

        tenancy()->initialize($this->globex);
        expect(DB::table($table)->where($key, $theirs->getKey())->count())->toBe(1);
    })->with(fn (): array => TenantModelInventory::seedablePrimary());
});

describe('nullable tenant tables', function (): void {
    it('shows platform audit entries only centrally and tenant entries only inside their tenant', function (): void {
        AuditLog::query()->create(['tenant_id' => null, 'actor_type' => 'system', 'action' => 'tenant.created', 'changes' => [], 'created_at' => now()]);
        $this->acme->run(fn () => AuditLog::query()->create(['tenant_id' => $this->acme->id, 'actor_type' => 'system', 'action' => 'settings.updated', 'changes' => [], 'created_at' => now()]));

        expect(DB::table('audit_logs')->pluck('action')->all())->toBe(['tenant.created'])
            ->and($this->acme->run(fn (): array => DB::table('audit_logs')->pluck('action')->all()))->toBe(['settings.updated'])
            ->and($this->globex->run(fn (): int => DB::table('audit_logs')->count()))->toBe(0);

        // A workspace cannot write a platform entry.
        tenancy()->initialize($this->acme);
        expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => null, 'actor_type' => 'system', 'action' => 'forged', 'changes' => '{}', 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security');
    });

    it('shows the global roles everywhere, custom roles only in their tenant, and lets no tenant change a global role', function (): void {
        $global = Role::query()->create(['name' => 'global-test-role', 'guard_name' => 'web', 'tenant_id' => null]);
        $this->acme->run(fn () => Role::query()->create(['name' => 'acme-only', 'guard_name' => 'web', 'tenant_id' => $this->acme->id]));

        $names = fn (): array => DB::table('roles')->orderBy('name')->pluck('name')->all();

        expect($names())->toBe(['global-test-role'])
            ->and($this->acme->run($names))->toBe(['acme-only', 'global-test-role'])
            ->and($this->globex->run($names))->toBe(['global-test-role']);

        tenancy()->initialize($this->acme);
        expect(DB::table('roles')->where('id', $global->id)->update(['name' => 'taken-over']))->toBe(0)
            ->and(DB::table('roles')->where('id', $global->id)->delete())->toBe(0);
    });
});

describe('setting lifecycle', function (): void {
    it('clears the setting when the tenant ends', function (): void {
        tenancy()->initialize($this->acme);
        expect(currentTenantSettingValue())->toBe($this->acme->id);

        tenancy()->end();
        expect(currentTenantSettingValue())->toBe('')
            ->and(DB::table('tickets')->count())->toBe(0);
    });

    it('puts the previous tenant back when a tenant run fails, also inside a transaction', function (): void {
        tenancy()->initialize($this->acme);

        try {
            DB::transaction(fn () => $this->globex->run(function (): void {
                DB::table('tickets')->insert(['id' => (string) Str::uuid7()]); // NOT NULL violations
            }));
        } catch (QueryException) {
            // expected
        }

        expect(tenant()?->getTenantKey())->toBe($this->acme->id)
            ->and(currentTenantSettingValue())->toBe($this->acme->id)
            ->and(DB::table('tickets')->count())->toBe(3);
    });

    it('re-applies the current context after a rollback undid a set_config', function (): void {
        try {
            DB::transaction(function (): void {
                tenancy()->initialize($this->acme);
                tenancy()->end();
                tenancy()->initialize($this->globex);

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            // expected
        }

        // The rollback reverted the setting to its value before the transaction (empty); the
        // bootstrapper wrote globex again because globex is still the current tenant.
        expect(tenant()?->getTenantKey())->toBe($this->globex->id)
            ->and(currentTenantSettingValue())->toBe($this->globex->id)
            ->and(DB::table('tickets')->count())->toBe(2);
    });

    it('never lets a tenant job\'s setting reach the next job on the same worker connection', function (): void {
        config(['queue.default' => 'database']);
        RlsProbeJob::$seen = [];

        // A statement closure: the PendingDispatch must be sent before the tenant run ends.
        $this->acme->run(function (): void {
            RlsProbeJob::dispatch('acme', fail: true);
        });
        RlsProbeJob::dispatch('central-after-failure');
        $this->globex->run(function (): void {
            RlsProbeJob::dispatch('globex');
        });
        RlsProbeJob::dispatch('central-after-success');

        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 2048]);

        expect(RlsProbeJob::$seen)->toBe([
            'acme' => ['setting' => $this->acme->id, 'tickets' => 3],
            'central-after-failure' => ['setting' => '', 'tickets' => 0],
            'globex' => ['setting' => $this->globex->id, 'tickets' => 2],
            'central-after-success' => ['setting' => '', 'tickets' => 0],
        ]);
    });

    it('clears a stale setting before a central job even when tenancy believes it has ended', function (): void {
        config(['queue.default' => 'database']);
        RlsProbeJob::$seen = [];
        RlsProbeJob::dispatch('central');

        // A setting left on the connection by anything that bypassed the bootstrapper.
        DB::select("SELECT set_config('app.current_tenant', ?, false)", [$this->acme->id]);
        expect(tenancy()->initialized)->toBeFalse()->and(DB::table('tickets')->count())->toBe(3);

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--memory' => 2048]);

        expect(RlsProbeJob::$seen['central'])->toBe(['setting' => '', 'tickets' => 0]);
    });
});
