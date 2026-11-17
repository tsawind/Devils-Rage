<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 39b: map_solarsystems.hole_slot_high keeps every hole number a system handed out
 * ("1245") instead of only the highest. A saved highest number becomes every number up to it.
 */
return new class extends Migration
{
    private const string SLOTS = '123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->string('hole_slot_high', 35)->nullable()->change();
        });

        DB::table('map_solarsystems')->whereNotNull('hole_slot_high')->orderBy('id')->each(function (object $row): void {
            $high = mb_strpos(self::SLOTS, mb_strtoupper((string) $row->hole_slot_high));
            DB::table('map_solarsystems')->where('id', $row->id)->update([
                'hole_slot_high' => $high === false ? null : mb_substr(self::SLOTS, 0, $high + 1),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('map_solarsystems')->whereNotNull('hole_slot_high')->orderBy('id')->each(function (object $row): void {
            DB::table('map_solarsystems')->where('id', $row->id)->update(['hole_slot_high' => mb_substr((string) $row->hole_slot_high, -1)]);
        });

        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->string('hole_slot_high', 1)->nullable()->change();
        });
    }
};
