<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 62h: seen while the pilot was playing that client (stricter proof, but worth knowing).
     */
    public function up(): void
    {
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->boolean('while_playing')->default(false)->after('strength');
        });
    }

    public function down(): void
    {
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->dropColumn('while_playing');
        });
    }
};
