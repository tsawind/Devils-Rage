<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 39: names.
 * - Stamps use "~" instead of "@" ("A1@1917" → "A1~1917"): "*@" now starts every way back.
 * - Way backs read "··*@{here} {sig} {hereclass}": a saved return format with "*{_}{here}"
 *   (or "* {here}") is switched over.
 * - map_solarsystems.old_aliases: names a system was known by before a rename ("was A1~1917").
 * - map_solarsystems.name_kept: someone chose "Keep old" for an old chain's name (no rename rows).
 * - map_solarsystems.hole_slot_high: the highest hole number a system ever handed out
 *   (numbers only count up; a freed number stays retired until 9 is reached).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_solarsystems', 'old_aliases')) {
                $table->json('old_aliases')->nullable();
            }
            if (! Schema::hasColumn('map_solarsystems', 'name_kept')) {
                $table->boolean('name_kept')->default(false);
            }
            if (! Schema::hasColumn('map_solarsystems', 'hole_slot_high')) {
                $table->string('hole_slot_high', 1)->nullable();
            }
        });

        Schema::table('maps', function (Blueprint $table): void {
            $table->string('bookmark_format_return')->default('{_}{_}*@{here} {sig} {hereclass}')->change();
        });

        foreach (['map_solarsystems', 'signatures'] as $table) {
            DB::table($table)
                ->where('alias', 'like', '%@%')
                ->update(['alias' => DB::raw("REPLACE(alias, '@', '~')")]);
        }

        foreach (['*{_}{here}', '* {here}'] as $old) {
            DB::table('maps')
                ->where('bookmark_format_return', 'like', '%'.$old.'%')
                ->update(['bookmark_format_return' => DB::raw(sprintf("REPLACE(bookmark_format_return, '%s', '*@{here}')", $old))]);
        }
    }

    public function down(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropColumn(['old_aliases', 'name_kept', 'hole_slot_high']);
        });
    }
};
