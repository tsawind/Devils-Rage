<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 40c: watching a hole no longer changes its mass math. "Every jump logged" is a switch
 * of its own in the watcher: from when it was turned on, only the jumps logged since count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_connections', 'mass_counting_since')) {
                $table->timestamp('mass_counting_since')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', fn (Blueprint $table) => $table->dropColumn('mass_counting_since'));
    }
};
