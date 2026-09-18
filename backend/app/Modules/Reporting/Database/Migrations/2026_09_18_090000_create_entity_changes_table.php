<?php

declare(strict_types=1);

use App\Modules\Reporting\Support\ReportableTables;
use App\Modules\Tenancy\Support\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Database change capture (ADR-0022 §1, docs/05-algorithms/history-and-time-analytics.md §2,
 * docs/08-database/entities.md §entity_changes).
 *
 * `record_entity_change()` writes one row per effective insert, update or delete of a reportable
 * table: the changed attributes only, the actor and request id from the session settings that
 * RlsTenancyBootstrapper sets, and a per-record version. Append-only: the runtime role may only
 * INSERT (through the trigger) and SELECT.
 */
return new class extends Migration
{
    private const EXISTING_TABLES = ['tenant_settings', 'users'];

    public function up(): void
    {
        Schema::create('entity_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entity_type', 64)->comment('table name');
            $table->uuid('entity_id');
            $table->integer('version')->comment('consecutive per entity, the replay order');
            $table->string('operation', 6);
            $table->jsonb('changes')->default('{}')->comment('{attribute: {old, new}}');
            $table->string('actor_type', 12)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('request_id', 64)->nullable();
            // Microseconds: several changes of one record can share a second, and occurred_at
            // decides which side of an instant a change falls on when history is replayed.
            $table->timestampTz('occurred_at', 6);

            // Leads with tenant_id like every other unique index, so it never reads across tenants.
            $table->unique(['tenant_id', 'entity_type', 'entity_id', 'version'], 'entity_changes_version_unique');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE entity_changes ADD CONSTRAINT entity_changes_operation_check CHECK (operation IN ('insert', 'update', 'delete'))");
        DB::statement("ALTER TABLE entity_changes ADD CONSTRAINT entity_changes_actor_type_check CHECK (actor_type IN ('user', 'api_client', 'system', 'email', 'platform'))");

        // Timelines, as-of replay and activity reports (docs/08-database/indexing.md).
        DB::statement('CREATE INDEX entity_changes_entity_idx ON entity_changes (tenant_id, entity_type, entity_id, occurred_at DESC)');
        DB::statement('CREATE INDEX entity_changes_tenant_time_idx ON entity_changes (tenant_id, occurred_at DESC)');
        DB::statement('CREATE INDEX entity_changes_actor_idx ON entity_changes (tenant_id, actor_id, occurred_at DESC)');

        TenantTables::protectTenantId('entity_changes');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION record_entity_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                old_j jsonb := CASE WHEN TG_OP <> 'INSERT' THEN to_jsonb(OLD) END;
                new_j jsonb := CASE WHEN TG_OP <> 'DELETE' THEN to_jsonb(NEW) END;
                row_j jsonb := coalesce(new_j, old_j);
                excluded text[] := TG_ARGV;
                diff jsonb := '{}'::jsonb;
                entity uuid := (row_j ->> 'id')::uuid;
                tenant uuid;
                k text;
            BEGIN
                FOR k IN SELECT jsonb_object_keys(row_j) LOOP
                    CONTINUE WHEN k = ANY (excluded) OR k = 'updated_at';
                    CONTINUE WHEN TG_OP = 'UPDATE' AND (old_j -> k) IS NOT DISTINCT FROM (new_j -> k);
                    diff := diff || jsonb_build_object(k, jsonb_build_object('old', old_j -> k, 'new', new_j -> k));
                END LOOP;

                -- An update that touched nothing reportable is not a change.
                IF TG_OP = 'UPDATE' AND diff = '{}'::jsonb THEN
                    RETURN NULL;
                END IF;

                -- The row's own tenant_id is the truth (it is NOT NULL and immutable); the session
                -- setting only covers a reportable table that has no tenant_id column.
                tenant := coalesce((row_j ->> 'tenant_id')::uuid, nullif(current_setting('app.current_tenant', true), '')::uuid);

                IF tenant IS NULL THEN
                    RAISE EXCEPTION 'record_entity_change(): no tenant for %(%)', TG_TABLE_NAME, entity USING ERRCODE = '23502';
                END IF;

                -- Rows deleted because their tenant is being deleted are not recorded: the history
                -- goes with the tenant, and entity_changes.tenant_id references tenants.
                IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM tenants WHERE id = tenant) THEN
                    RETURN NULL;
                END IF;

                INSERT INTO entity_changes (id, tenant_id, entity_type, entity_id, version, operation, changes,
                                            actor_type, actor_id, request_id, occurred_at)
                VALUES (uuidv7(), tenant, TG_TABLE_NAME, entity,
                        coalesce((SELECT max(version) FROM entity_changes
                                  WHERE tenant_id = tenant AND entity_type = TG_TABLE_NAME
                                    AND entity_id = entity), 0) + 1,
                        lower(TG_OP), diff,
                        coalesce(nullif(current_setting('app.actor_type', true), ''), 'system'),
                        nullif(current_setting('app.actor_id', true), '')::uuid,
                        nullif(current_setting('app.request_id', true), ''),
                        clock_timestamp());

                RETURN NULL;
            END;
            $$;
            SQL);

        // The reportable tables that exist when this migration runs. Fixed, not read from the
        // registry: the registry grows with later migrations, which attach their own trigger.
        foreach (self::EXISTING_TABLES as $table) {
            ReportableTables::captureChanges($table);
        }

        $runtimeRole = (string) config('database.connections.pgsql.username');
        $roleExists = DB::selectOne('SELECT 1 AS present FROM pg_roles WHERE rolname = ? AND rolname <> current_user', [$runtimeRole]);

        if ($roleExists !== null) {
            DB::statement(sprintf('REVOKE UPDATE, DELETE, TRUNCATE ON entity_changes FROM %s', DB::getQueryGrammar()->wrap($runtimeRole)));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::EXISTING_TABLES as $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_changes ON {$table}");
            }

            DB::unprepared('DROP FUNCTION IF EXISTS record_entity_change()');
        }

        Schema::dropIfExists('entity_changes');
    }
};
