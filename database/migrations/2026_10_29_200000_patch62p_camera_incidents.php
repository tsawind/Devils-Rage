<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 62p: what one client's triggers see within a minute is one incident (the first
     * event; the rest point at it), with one clip and picture of the whole client.
     */
    public function up(): void
    {
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->foreignId('incident_id')->nullable()->after('map_camera_id')->constrained('map_camera_events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('incident_id');
        });
    }
};
