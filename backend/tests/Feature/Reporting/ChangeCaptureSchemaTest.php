<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The registry and the database must agree: a later migration that creates a reportable table but
 * forgets ReportableTables::captureChanges() (or attaches a trigger without registering the table)
 * fails here (M1-23, ADR-0022 §1).
 *
 * @return array<string, string> trigger definition per table, for triggers named <table>_changes
 */
function captureTriggers(): array
{
    return DB::table('pg_trigger as t')
        ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
        ->join('pg_proc as p', 'p.oid', '=', 't.tgfoid')
        ->where('p.proname', 'record_entity_change')
        ->where('t.tgisinternal', false)
        ->selectRaw('c.relname as table_name, pg_get_triggerdef(t.oid) as definition')
        ->pluck('definition', 'table_name')
        ->all();
}

it('attaches the capture trigger to every reportable table, with the registered exclusions', function (string $table): void {
    $definition = captureTriggers()[$table] ?? null;

    $arguments = implode(', ', array_map(
        static fn (string $column): string => "'{$column}'",
        ReportableTables::excludedColumns($table),
    ));

    expect($definition)->not->toBeNull("{$table} has no {$table}_changes trigger")
        ->and($definition)->toContain("CREATE TRIGGER {$table}_changes")
        ->and($definition)->toContain('AFTER INSERT')
        ->and($definition)->toContain('DELETE')
        ->and($definition)->toContain('UPDATE')
        ->and($definition)->toContain('FOR EACH ROW')
        ->and($definition)->toContain("EXECUTE FUNCTION record_entity_change({$arguments})");
})->with(ReportableTables::all());

it('captures nothing that is not in the registry', function (): void {
    expect(array_keys(captureTriggers()))->toEqualCanonicalizing(ReportableTables::all());
});

it('keeps every reportable table tenant-scoped', function (): void {
    // entity_changes.tenant_id is NOT NULL, so a reportable table must carry a tenant.
    expect(ReportableTables::all())->each->toBeIn(TenantTables::PRIMARY);
});

it('makes entity_changes append-only for the runtime role', function (): void {
    $table = DB::table('entity_changes');

    expect(fn () => $table->update(['request_id' => 'nope']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('entity_changes')->delete())->toThrow(QueryException::class);
});
