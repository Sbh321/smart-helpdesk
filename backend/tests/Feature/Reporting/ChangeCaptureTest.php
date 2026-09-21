<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Reporting\Domain\History\ChangeOperation;
use App\Modules\Reporting\Domain\History\ChangeReplayer;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The `record_entity_change()` trigger (M1-23, docs/05-algorithms/history-and-time-analytics.md §2).
 * Contacts and tickets arrive in M2, so the captured subjects here are `users` (which has excluded
 * columns) and `tenant_settings`.
 */

final class RenameUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        User::query()->findOrFail($this->userId)->update(['name' => 'Renamed by the job']);
    }
}

/**
 * The captured rows of one record, oldest first, read in every workspace in turn (row-level
 * security shows a connection one workspace at a time).
 *
 * @return list<array<string, mixed>>
 */
function capturedRows(string $table, string $entityId): array
{
    $rows = [];
    foreach (Tenant::query()->cursor() as $tenant) {
        array_push($rows, ...$tenant->run(fn (): array => DB::table('entity_changes')
            ->where('entity_type', $table)
            ->where('entity_id', $entityId)
            ->get()
            ->all()));
    }
    usort($rows, fn (object $a, object $b): int => $a->version <=> $b->version);

    return array_map(function (object $row): array {
        $values = (array) $row;
        $values['changes'] = json_decode((string) $row->changes, true);

        return $values;
    }, $rows);
}

beforeEach(function (): void {
    $this->acme = createTenant('acme');
    $this->globex = createTenant('globex');
});

afterEach(function (): void {
    tenancy()->end();
});

it('writes one row per insert, update and delete, with the acting user and the diff', function (): void {
    $actor = createTenantUser($this->acme, ['name' => 'Asha']);
    actingAsTenantUser($this->acme, $actor);
    tenancy()->initialize($this->acme);

    $subject = User::factory()->create(['name' => 'Bikash', 'email' => 'bikash@acme.test']);
    $subject->update(['name' => 'Bikash Rana']);
    $subject->delete();

    $rows = capturedRows('users', $subject->id);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'operation'))->toBe(['insert', 'update', 'delete'])
        ->and(array_column($rows, 'version'))->toBe([1, 2, 3])
        ->and(array_column($rows, 'tenant_id'))->each->toBe($this->acme->id)
        ->and(array_column($rows, 'actor_type'))->each->toBe('user')
        ->and(array_column($rows, 'actor_id'))->each->toBe($actor->id);

    expect($rows[0]['changes'])->toHaveKeys(['id', 'tenant_id', 'name', 'email', 'created_at'])
        ->and($rows[0]['changes']['name'])->toEqual(['old' => null, 'new' => 'Bikash'])
        ->and($rows[1]['changes'])->toEqual(['name' => ['old' => 'Bikash', 'new' => 'Bikash Rana']])
        ->and($rows[2]['changes']['email'])->toEqual(['old' => 'bikash@acme.test', 'new' => null]);
});

it('never records an excluded column or updated_at', function (): void {
    tenancy()->initialize($this->acme);
    $subject = User::factory()->create();

    $subject->password = 'a-new-hash';
    $subject->setRememberToken('a-fresh-token');
    $subject->save();
    $subject->delete();

    foreach (capturedRows('users', $subject->id) as $row) {
        expect($row['changes'])->not->toHaveKey('password')
            ->and($row['changes'])->not->toHaveKey('remember_token')
            ->and($row['changes'])->not->toHaveKey('updated_at');
    }

    // Only the insert and the delete: an update of excluded columns alone changes nothing reportable.
    expect(capturedRows('users', $subject->id))->toHaveCount(2);
});

it('writes nothing for an update that changes nothing reportable', function (): void {
    tenancy()->initialize($this->acme);
    $subject = User::factory()->create(['name' => 'Chen']);

    DB::table('users')->where('id', $subject->id)->update(['name' => 'Chen', 'updated_at' => now()->addHour()]);

    expect(capturedRows('users', $subject->id))->toHaveCount(1);
});

it('captures raw writes and other reportable tables too', function (): void {
    tenancy()->initialize($this->acme);

    $id = (string) Str::uuid7();
    DB::table('tenant_settings')->insert([
        'id' => $id,
        'tenant_id' => $this->acme->id,
        'data' => json_encode(['locale' => 'en']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('tenant_settings')->where('id', $id)->update(['data' => json_encode(['locale' => 'ne'])]);

    $rows = capturedRows('tenant_settings', $id);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['changes']['data'])->toEqual(['old' => ['locale' => 'en'], 'new' => ['locale' => 'ne']]);
});

it('counts versions per record, not per table', function (): void {
    tenancy()->initialize($this->acme);

    $first = User::factory()->create();
    $second = User::factory()->create();
    $first->update(['name' => 'Second version']);
    $second->update(['name' => 'Also a second version']);
    $first->update(['name' => 'Third version']);

    expect(array_column(capturedRows('users', $first->id), 'version'))->toBe([1, 2, 3])
        ->and(array_column(capturedRows('users', $second->id), 'version'))->toBe([1, 2]);
});

it('records the request id the bootstrapper put in the session', function (): void {
    Context::add('request_id', 'req-01HZ');
    tenancy()->initialize($this->acme);

    $subject = User::factory()->create();

    expect(capturedRows('users', $subject->id)[0]['request_id'])->toBe('req-01HZ');
});

describe('actor', function (): void {
    it('is system for a write with no authenticated user', function (): void {
        tenancy()->initialize($this->acme);
        $subject = User::factory()->create();

        $row = capturedRows('users', $subject->id)[0];

        expect($row['actor_type'])->toBe('system')
            ->and($row['actor_id'])->toBeNull();
    });

    it('is system inside a queued job', function (): void {
        config(['queue.default' => 'database']);
        tenancy()->initialize($this->acme);
        $subject = User::factory()->create();
        RenameUserJob::dispatch($subject->id);
        tenancy()->end();

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

        $rows = capturedRows('users', $subject->id);

        expect($rows)->toHaveCount(2)
            ->and($rows[1]['changes'])->toEqual(['name' => ['old' => $subject->name, 'new' => 'Renamed by the job']])
            ->and($rows[1]['actor_type'])->toBe('system')
            ->and($rows[1]['actor_id'])->toBeNull()
            ->and($rows[1]['tenant_id'])->toBe($this->acme->id);
    });

    it('is system inside a console command run per tenant', function (): void {
        tenancy()->initialize($this->acme);
        $subject = User::factory()->create();
        tenancy()->end();

        Artisan::command('test:rename-user {id}', function (string $id): void {
            User::query()->findOrFail($id)->update(['name' => 'Renamed by the command']);
        });
        Artisan::call('tenants:run', [
            'commandname' => 'test:rename-user',
            '--tenants' => [$this->acme->id],
            '--argument' => ["id={$subject->id}"],
        ]);

        $row = capturedRows('users', $subject->id)[1];

        expect($row['actor_type'])->toBe('system')
            ->and($row['actor_id'])->toBeNull()
            ->and($row['changes'])->toEqual(['name' => ['old' => $subject->name, 'new' => 'Renamed by the command']]);
    });

    it('follows a user authenticated after tenancy was initialised', function (): void {
        tenancy()->initialize($this->acme);
        $subject = User::factory()->create();
        $actor = createTenantUser($this->acme);

        // The tenant middleware group initialises tenancy before auth:sanctum runs; the
        // Authenticated event is what tells the bootstrapper to re-read the actor.
        Auth::guard('web')->setUser($actor);
        $subject->update(['name' => 'After login']);

        $rows = capturedRows('users', $subject->id);

        expect($rows[0]['actor_type'])->toBe('system')
            ->and($rows[1]['actor_type'])->toBe('user')
            ->and($rows[1]['actor_id'])->toBe($actor->id);
    });
});

describe('isolation', function (): void {
    it('hides the changes of another tenant', function (): void {
        tenancy()->initialize($this->globex);
        $theirs = User::factory()->create();
        tenancy()->end();

        tenancy()->initialize($this->acme);
        $mine = User::factory()->create();

        $visible = EntityChange::query()->pluck('entity_id')->all();

        expect($visible)->toContain($mine->id)
            ->and($visible)->not->toContain($theirs->id)
            // Row-level security hides them from a raw query as well; they exist inside globex.
            ->and(DB::table('entity_changes')->where('entity_id', $theirs->id)->count())->toBe(0)
            ->and(capturedRows('users', $theirs->id))->toHaveCount(1);
    });

    it('files a change under the tenant of the row', function (): void {
        tenancy()->initialize($this->acme);
        // The factory stores globex's row inside globex; a row of another workspace cannot even be
        // written from acme's session (row-level security), so the row's tenant is the one filed.
        $theirs = User::factory()->forTenant($this->globex)->create();

        expect(capturedRows('users', $theirs->id)[0]['tenant_id'])->toBe($this->globex->id)
            ->and(fn () => DB::transaction(fn () => DB::table('users')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->globex->id, 'name' => 'X', 'email' => 'x@globex.test',
            ])))->toThrow(QueryException::class, 'row-level security');
    });
});

it('stores rows the replayer can read back', function (): void {
    tenancy()->initialize($this->acme);

    $subject = User::factory()->create(['name' => 'Priya', 'email' => 'priya@acme.test']);
    $subject->update(['name' => 'Priya Sharma']);
    $subject->update(['is_active' => false]);

    $changes = EntityChange::query()
        ->where('entity_type', 'users')
        ->where('entity_id', $subject->id)
        ->orderBy('version')
        ->get();

    expect($changes)->toHaveCount(3)
        ->and($changes[0]->toRecordedChange()->operation)->toBe(ChangeOperation::Insert);

    $history = $changes->map(fn (EntityChange $change) => $change->toRecordedChange())->all();
    $replayer = new ChangeReplayer;
    $now = CarbonImmutable::now()->addSecond();

    $current = $replayer->forwardTo($history, $now);

    expect($current)->not->toBeNull()
        ->and($current['name'])->toBe('Priya Sharma')
        ->and($current['email'])->toBe('priya@acme.test')
        ->and($current['is_active'])->toBeFalse();

    // Backward replay from that state lands on the values of each earlier moment.
    $beforeRename = $replayer->asOf($current, $history, $changes[1]->occurred_at->subMicrosecond());
    $beforeInsert = $replayer->asOf($current, $history, $changes[0]->occurred_at->subMicrosecond());

    expect($beforeRename['name'])->toBe('Priya')
        ->and($beforeRename['is_active'])->toBeTrue()
        ->and($beforeInsert)->toBeNull();
});
