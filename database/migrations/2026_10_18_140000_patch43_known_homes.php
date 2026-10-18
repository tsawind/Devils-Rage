<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 43: whose home a system is ("The Devil's Rage" for Daisy). Kept with the map's
 * intel on the system, so it stays when the system leaves the map and pings when it's
 * connected again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_solarsystem_details', function (Blueprint $table): void {
            $table->string('home_of', 100)->nullable()->after('occupier_alias');
        });
    }

    public function down(): void
    {
        Schema::table('map_solarsystem_details', function (Blueprint $table): void {
            $table->dropColumn('home_of');
        });
    }
};
