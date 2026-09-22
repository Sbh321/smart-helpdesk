<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tickets created from inbound email (M3-19, docs/04-domain/email.md) record `created_via = email`;
 * the reporting channel labels already knew the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_created_via_check');
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_created_via_check CHECK (created_via IN ('ui', 'api', 'email', 'seed'))");
    }

    public function down(): void
    {
        // Existing email tickets keep their value: NOT VALID applies the old rule to new rows only
        // (a data fix would have to run inside every tenant, docs/08-database/tenancy.md).
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_created_via_check');
        DB::statement("ALTER TABLE tickets ADD CONSTRAINT tickets_created_via_check CHECK (created_via IN ('ui', 'api', 'seed')) NOT VALID");
    }
};
