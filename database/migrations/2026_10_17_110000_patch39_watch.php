<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 39: fresh + watching. A hole marked fresh counts only the jumps logged after the
 * mark ("ours only"); the watch banner shows open or folded to a chip, and asks "still on
 * it?" after 30 min without a click. Hand-added jumps say who (hostile / friendly), the
 * ship class and its fit (Higgs, prop, plate), and the direction when known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_connections', 'mass_watch_open')) {
                $table->boolean('mass_watch_open')->default(false);
            }
            if (! Schema::hasColumn('map_connections', 'mass_watch_touched_at')) {
                $table->timestamp('mass_watch_touched_at')->nullable();
            }
        });

        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_connection_jumps', 'side')) {
                $table->string('side', 16)->nullable();
            }
            if (! Schema::hasColumn('map_connection_jumps', 'ship_class')) {
                $table->string('ship_class', 16)->nullable();
            }
            if (! Schema::hasColumn('map_connection_jumps', 'direction_known')) {
                $table->boolean('direction_known')->default(true);
            }
            if (! Schema::hasColumn('map_connection_jumps', 'fit')) {
                $table->json('fit')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn(['mass_watch_open', 'mass_watch_touched_at']);
        });
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->dropColumn(['side', 'ship_class', 'direction_known', 'fit']);
        });
    }
};
