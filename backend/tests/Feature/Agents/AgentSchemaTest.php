<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return list<array{child_columns: string, parent_table: string, parent_columns: string}>
 */
function agentCompositeForeignKeys(string $table): array
{
    return array_map(
        static fn (object $row): array => [
            'child_columns' => (string) $row->child_columns,
            'parent_table' => (string) $row->parent_table,
            'parent_columns' => (string) $row->parent_columns,
        ],
        DB::select(<<<'SQL'
            SELECT string_agg(child.attname, ',' ORDER BY key_position.ordinality) AS child_columns,
                   parent_table.relname AS parent_table,
                   string_agg(parent.attname, ',' ORDER BY key_position.ordinality) AS parent_columns
            FROM pg_constraint constraint_row
            JOIN pg_class child_table ON child_table.oid = constraint_row.conrelid
            JOIN pg_class parent_table ON parent_table.oid = constraint_row.confrelid
            JOIN LATERAL unnest(constraint_row.conkey) WITH ORDINALITY key_position(attnum, ordinality) ON true
            JOIN pg_attribute child ON child.attrelid = child_table.oid AND child.attnum = key_position.attnum
            JOIN pg_attribute parent ON parent.attrelid = parent_table.oid
                AND parent.attnum = constraint_row.confkey[key_position.ordinality]
            WHERE constraint_row.contype = 'f' AND child_table.relname = ?
            GROUP BY constraint_row.oid, parent_table.relname
            ORDER BY constraint_row.oid
            SQL, [$table]),
    );
}

it('creates the agent directory tables with their documented columns', function (): void {
    expect(Schema::hasTable('teams'))->toBeTrue()
        ->and(Schema::hasTable('agent_profiles'))->toBeTrue()
        ->and(Schema::hasTable('team_members'))->toBeTrue()
        ->and(Schema::hasTable('agent_skills'))->toBeTrue()
        ->and(Schema::hasTable('agent_shifts'))->toBeTrue()
        ->and(Schema::hasColumns('agent_profiles', [
            'id',
            'tenant_id',
            'user_id',
            'capacity',
            'availability',
            'active_ticket_count',
            'last_assigned_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('agent_skills', ['tenant_id', 'agent_profile_id', 'skill_id', 'level']))->toBeTrue()
        ->and(Schema::hasColumns('agent_shifts', [
            'id',
            'tenant_id',
            'agent_profile_id',
            'weekday',
            'date',
            'starts_at',
            'ends_at',
            'is_off',
        ]))->toBeTrue();
});

it('installs the missing tenant-safe ticket and category foreign keys', function (): void {
    expect(agentCompositeForeignKeys('categories'))->toContain([
        'child_columns' => 'tenant_id,default_team_id',
        'parent_table' => 'teams',
        'parent_columns' => 'tenant_id,id',
    ])->and(agentCompositeForeignKeys('tickets'))->toContain([
        'child_columns' => 'tenant_id,team_id',
        'parent_table' => 'teams',
        'parent_columns' => 'tenant_id,id',
    ])->and(agentCompositeForeignKeys('tickets'))->toContain([
        'child_columns' => 'tenant_id,assigned_agent_id',
        'parent_table' => 'agent_profiles',
        'parent_columns' => 'tenant_id,id',
    ]);
});
