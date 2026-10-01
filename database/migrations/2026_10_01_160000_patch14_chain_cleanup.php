<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 14: a combat system converted during cleanup still needs its way back
 * re-bookmarked in game ("*" → the new return name) until someone ticks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->boolean('cleanup_return_pending')->default(false)->after('combat_previous_color');
        });
    }

    public function down(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropColumn('cleanup_return_pending');
        });
    }
};
