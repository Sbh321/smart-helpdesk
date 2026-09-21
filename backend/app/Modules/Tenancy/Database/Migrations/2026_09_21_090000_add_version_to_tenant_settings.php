<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The settings version (docs/03-architecture/configuration.md): it grows by one on every write and is
 * stored with algorithm results, so a result can be traced to the settings that produced it.
 * Zero means "code defaults only".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
