<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: "Use frigate holes" in the route settings (on: routes may go through
 * frigate-only holes, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('route_use_frigate_holes')->default(true)->after('route_use_wormholes');
        });
    }

    public function down(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->dropColumn('route_use_frigate_holes');
        });
    }
};
