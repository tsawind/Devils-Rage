<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: 👁 Watching: someone saw the hole new and every jump through it is logged, so
 * what's left is its real size minus the log (a much tighter range).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->timestamp('mass_watched_at')->nullable();
            $table->string('mass_watched_by_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn(['mass_watched_at', 'mass_watched_by_name']);
        });
    }
};
