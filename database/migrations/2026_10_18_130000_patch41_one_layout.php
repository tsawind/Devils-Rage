<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 41c: one card layout per person for every map tab. The layout saved last (on any
 * map) is the one every map uses. Existing saved layouts count as saved when they were last
 * changed, so each person's current layout (their Daisy one) carries over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_user_settings', 'layout_saved_at')) {
                $table->timestamp('layout_saved_at')->nullable();
            }
        });

        DB::table('map_user_settings')
            ->whereNotNull('layout_breakpoints')
            ->whereNull('layout_saved_at')
            ->update(['layout_saved_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('map_user_settings', fn (Blueprint $table) => $table->dropColumn('layout_saved_at'));
    }
};
