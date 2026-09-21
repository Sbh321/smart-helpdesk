<?php

declare(strict_types=1);

use App\Modules\Media\Domain\MediaUsage;
use App\Modules\Media\Support\MediaQuota;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/MediaTestSupport.php';

/*
 * MediaQuota is SQL (an advisory lock, an upsert and a clamped UPDATE … RETURNING), so its unit
 * is exercised against PostgreSQL here rather than in tests/Unit, which never boots the application.
 */

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
    $this->quota = app(MediaQuota::class);
});

function counterOf(Tenant $tenant): ?int
{
    $value = DB::table('tenant_counters')->where('tenant_id', $tenant->getKey())->value('storage_used_bytes');

    return $value === null ? null : (int) $value;
}

it('creates the counter row on the first add and sums later ones', function (): void {
    DB::table('tenant_counters')->where('tenant_id', $this->acme->getKey())->delete();

    $this->quota->add($this->acme->getKey(), 300);
    $this->quota->add($this->acme->getKey(), 200);

    expect(counterOf($this->acme))->toBe(500)
        ->and(counterOf($this->globex) ?? 0)->toBe(0);
});

it('does not disturb ticket numbering on the shared counter row', function (): void {
    $columns = array_diff(array_keys((array) DB::table('tenant_counters')->where('tenant_id', $this->acme->getKey())->first()), ['storage_used_bytes']);
    $this->quota->add($this->acme->getKey(), 1);   // makes sure the row exists
    $before = (array) DB::table('tenant_counters')->where('tenant_id', $this->acme->getKey())->first();

    $this->quota->add($this->acme->getKey(), 100);
    $this->quota->release($this->acme->getKey(), 50);

    $after = (array) DB::table('tenant_counters')->where('tenant_id', $this->acme->getKey())->first();
    foreach ($columns as $column) {
        expect($after[$column])->toBe($before[$column]);
    }
    expect((int) $after['storage_used_bytes'])->toBe((int) $before['storage_used_bytes'] + 50);
});

it('releases bytes and clamps at zero with a warning', function (): void {
    Log::spy();
    $this->quota->add($this->acme->getKey(), 500);

    $this->quota->release($this->acme->getKey(), 200);
    expect(counterOf($this->acme))->toBe(300);
    Log::shouldNotHaveReceived('warning');

    $this->quota->release($this->acme->getKey(), 301);
    expect(counterOf($this->acme))->toBe(0);
    Log::shouldHaveReceived('warning')->once()->with('media.quota.clamped', [
        'tenant_id' => $this->acme->getKey(), 'released_bytes' => 301, 'previous_bytes' => 300,
    ]);
});

it('ignores a release for a workspace without a counter row', function (): void {
    DB::table('tenant_counters')->where('tenant_id', $this->acme->getKey())->delete();

    $this->quota->release($this->acme->getKey(), 100);

    expect(counterOf($this->acme))->toBeNull();
});

it('reports used, quota and reserved bytes per tenant from one snapshot', function (): void {
    DB::table('tenants')->where('id', $this->acme->getKey())->update(['storage_quota_bytes' => 2000]);
    $this->quota->add($this->acme->getKey(), 700);
    $this->quota->add($this->globex->getKey(), 9000);
    mediaItemIn($this->acme, 'pending', ['size_bytes' => 100]);
    mediaItemIn($this->acme, 'pending', ['size_bytes' => 50]);
    mediaItemIn($this->acme, 'ready', ['size_bytes' => 400]);
    mediaItemIn($this->acme, 'failed', ['size_bytes' => 400]);
    mediaItemIn($this->globex, 'pending', ['size_bytes' => 5000]);

    expect($this->quota->usage($this->acme->getKey()))->toEqual(new MediaUsage(700, 2000, 150))
        ->and($this->quota->usage('01920000-0000-7000-8000-000000000000'))->toEqual(new MediaUsage(0, 0, 0));
});

it('takes a transaction-scoped advisory lock per tenant', function (): void {
    $held = fn (): int => (int) DB::selectOne("SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()")->n;
    $before = $held();

    // Released at the end of the transaction, which under RefreshDatabase is the test's own.
    DB::transaction(function () use ($held, $before): void {
        $this->quota->lock($this->acme->getKey());
        $this->quota->lock($this->acme->getKey());     // re-entrant
        $this->quota->lock($this->globex->getKey());
        expect($held())->toBe($before + 2);
    });
});
