<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 32: when a connection's mass status last changed, so only jumps logged since then
 * count against the status band (marking a hole reduced or crit "resets" the jump log).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->timestamp('mass_status_updated_at')->nullable()->after('mass_status');
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn('mass_status_updated_at');
        });
    }
};
