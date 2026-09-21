<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Comment bodies were recorded in entity_changes from M2-07 on, but ADR-0022 captures comment metadata
 * only. The trigger is re-created with `body` excluded and the bodies already recorded are removed. The
 * trigger arguments are written out here rather than read from ReportableTables, because a migration
 * must not change when that registry does. Irreversible on the data side: the removed bodies are gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS ticket_comments_changes ON ticket_comments');
        DB::statement("CREATE TRIGGER ticket_comments_changes AFTER INSERT OR UPDATE OR DELETE ON ticket_comments FOR EACH ROW EXECUTE FUNCTION record_entity_change('body')");

        // entity_changes is append-only for the runtime role; migrations run as the owner.
        DB::statement("UPDATE entity_changes SET changes = changes - 'body' WHERE entity_type = 'ticket_comments' AND jsonb_exists(changes, 'body')");
        DB::statement("DELETE FROM entity_changes WHERE entity_type = 'ticket_comments' AND operation = 'update' AND changes = '{}'::jsonb");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS ticket_comments_changes ON ticket_comments');
        DB::statement('CREATE TRIGGER ticket_comments_changes AFTER INSERT OR UPDATE OR DELETE ON ticket_comments FOR EACH ROW EXECUTE FUNCTION record_entity_change()');
    }
};
