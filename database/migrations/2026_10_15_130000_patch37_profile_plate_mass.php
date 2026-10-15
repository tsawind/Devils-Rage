<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: the plates' mass in a SeAT fit (after Armor Layering), for "Plates off" on a
 * capital's profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('plate_mass')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->dropColumn('plate_mass');
        });
    }
};
