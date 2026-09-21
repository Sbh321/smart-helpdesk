<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantModelInventory;

/*
 * Item 4 of the mandatory isolation suite (docs/10-quality/testing.md), read from the live catalog
 * rather than from the migrations, so a table shaped by hand still has to obey the contract:
 * tenant_id uuid NOT NULL with a foreign key, every business unique index led by tenant_id, the
 * immutability trigger in place, and a runtime role that cannot step over row-level security.
 *
 * The row-level security half of item 4 (ENABLE/FORCE and pg_policies per table) and item 5
 * (raw-query backstop, fail-closed with the setting unset) are in RowLevelSecurityTest.php (M3-07).
 */

/**
 * @return list<string>
 */
function tenantTablesWithColumns(): array
{
    return [...TenantTables::PRIMARY, ...TenantTables::NULLABLE, ...TenantTables::CREDENTIALS];
}

/**
 * Unique indexes of a table with the plain columns they cover; expression parts (lower(email))
 * contribute no column name, which is exactly what the "must include tenant_id" rule needs.
 *
 * @return list<array{name: string, primary: bool, columns: list<string>}>
 */
function uniqueIndexesOf(string $table): array
{
    $rows = DB::select(<<<'SQL'
        SELECT i.relname AS name,
               ix.indisprimary AS is_primary,
               COALESCE(
                   array_to_string(array_agg(a.attname ORDER BY a.attname) FILTER (WHERE a.attname IS NOT NULL), ','),
                   ''
               ) AS columns
        FROM pg_index ix
        JOIN pg_class t ON t.oid = ix.indrelid
        JOIN pg_class i ON i.oid = ix.indexrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        LEFT JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY (ix.indkey)
        WHERE ix.indisunique AND n.nspname = 'public' AND t.relname = ?
        GROUP BY i.relname, ix.indisprimary
        SQL, [$table]);

    return array_map(fn (object $row): array => [
        'name' => (string) $row->name,
        'primary' => (bool) $row->is_primary,
        'columns' => $row->columns === '' ? [] : explode(',', (string) $row->columns),
    ], $rows);
}

it('lists only tables that exist', function (): void {
    foreach ([...tenantTablesWithColumns(), ...TenantModelInventory::UNSCOPED_TABLES_WITH_TENANT_ID] as $table) {
        expect(DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->exists())->toBeTrue("TenantTables names {$table}, which is not in the schema.");
    }
});

it('gives every primary tenant table a tenant_id uuid NOT NULL', function (): void {
    foreach (TenantTables::PRIMARY as $table) {
        $column = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', 'tenant_id')
            ->first(['data_type', 'is_nullable']);

        expect($column)->not->toBeNull("{$table} has no tenant_id column.")
            ->and($column->data_type)->toBe('uuid')
            ->and($column->is_nullable)->toBe('NO', "{$table}.tenant_id must be NOT NULL.");
    }
});

it('keeps the nullable and credential tables on a uuid tenant_id too', function (): void {
    foreach ([...TenantTables::NULLABLE, ...TenantTables::CREDENTIALS] as $table) {
        $column = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', 'tenant_id')
            ->first(['data_type', 'is_nullable']);

        expect($column)->not->toBeNull("{$table} has no tenant_id column.")
            ->and($column->data_type)->toBe('uuid');

        // Credential tables may be NOT NULL (an OAuth client always has a workspace); sessions and
        // Sanctum tokens are written before the tenant is known and stay nullable.
        if (in_array($table, TenantTables::NULLABLE, true)) {
            expect($column->is_nullable)->toBe('YES', "{$table}.tenant_id is documented as nullable.");
        }
    }
});

it('points tenant_id at the tenants table with a foreign key', function (): void {
    // Credential tables are written before the tenant is known, so they carry no constraint.
    // Only single-column keys count: composite (tenant_id, x_id) keys point at the parent table
    // on purpose (docs/08-database/tenancy.md §Cross-tenant foreign keys).
    foreach ([...TenantTables::PRIMARY, ...TenantTables::NULLABLE] as $table) {
        $referenced = DB::selectOne(<<<'SQL'
            SELECT confrelid::regclass::text AS referenced
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.conrelid = ?::regclass AND a.attname = 'tenant_id'
              AND array_length(c.conkey, 1) = 1
            SQL, [$table]);

        expect($referenced)->not->toBeNull("{$table}.tenant_id has no foreign key.")
            ->and($referenced->referenced)->toBe('tenants');
    }
});

it('leads every business unique index with tenant_id', function (): void {
    foreach (TenantTables::PRIMARY as $table) {
        foreach (uniqueIndexesOf($table) as $index) {
            // The surrogate primary key is a UUID v7 and is globally unique by construction; every
            // other unique index states a per-tenant rule and must be scoped.
            if ($index['primary'] && $index['columns'] === ['id']) {
                continue;
            }

            if (in_array($index['name'], TenantModelInventory::UNIQUE_INDEX_EXCEPTIONS, true)) {
                continue;
            }

            expect(in_array('tenant_id', $index['columns'], true))->toBeTrue(
                "{$index['name']} on {$table} is unique across tenants; add tenant_id to it."
            );
        }
    }
});

it('protects tenant_id with the immutability trigger on every primary table', function (): void {
    foreach (TenantTables::PRIMARY as $table) {
        $triggers = array_map(
            fn (object $row): string => (string) $row->tgname,
            DB::select('SELECT tgname FROM pg_trigger WHERE tgrelid = ?::regclass AND NOT tgisinternal', [$table]),
        );

        expect(in_array("{$table}_tenant_id_immutable", $triggers, true))->toBeTrue(
            "The migration for {$table} must call TenantTables::protectTenantId()."
        );
    }
});

it('runs the application as a role that can neither own the tables nor bypass row-level security', function (): void {
    $role = DB::selectOne('SELECT current_user AS name, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

    expect($role->name)->toBe(config('database.connections.pgsql.username'))
        ->and($role->name)->not->toBe(config('database.connections.pgsql_owner.username'))
        ->and((bool) $role->rolsuper)->toBeFalse()
        ->and((bool) $role->rolbypassrls)->toBeFalse();

    $owned = DB::table('pg_tables')
        ->where('schemaname', 'public')
        ->whereIn('tablename', tenantTablesWithColumns())
        ->where('tableowner', $role->name)
        ->pluck('tablename')
        ->all();

    expect($owned)->toBe([], 'The runtime role owns tables, so FORCE ROW LEVEL SECURITY would not apply to it.');
});
