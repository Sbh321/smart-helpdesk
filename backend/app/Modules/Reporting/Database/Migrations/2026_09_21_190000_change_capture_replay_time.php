<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The demo seeder (M3-13) replays ninety days of work through the real actions under a frozen clock, so
 * ticket history, intervals and snapshots carry past instants. The change-capture trigger stamped
 * `occurred_at` with clock_timestamp(), which put every replayed entity change at seed time and left the
 * as-of views empty before it. The trigger now takes the instant from the session setting
 * `app.occurred_at` when a connection has set it, and from clock_timestamp() otherwise. Only the
 * replay sets it (App\Modules\Demo\Seeding\ReplayClock), and it is cleared when the replay ends; every
 * other connection leaves it unset and gets clock_timestamp() exactly as before. Setting it needs no
 * privilege beyond what the connection already has, the same trust as app.actor_id.
 *
 * The function body is written out: a migration must not change when later code does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(self::function("coalesce(nullif(current_setting('app.occurred_at', true), '')::timestamptz, clock_timestamp())"));
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(self::function('clock_timestamp()'));
    }

    private static function function(string $occurredAt): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION record_entity_change() RETURNS trigger
            LANGUAGE plpgsql AS \$\$
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
                        {$occurredAt});

                RETURN NULL;
            END;
            \$\$;
            SQL;
    }
};
