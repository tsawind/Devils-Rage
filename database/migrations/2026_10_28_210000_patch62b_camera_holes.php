<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 62b: a camera can watch a hole nobody has jumped yet (a dotted pipe, i.e. its
     * signature); once someone jumps it, the camera moves to the new pipe.
     */
    public function up(): void
    {
        Schema::table('map_cameras', function (Blueprint $table): void {
            $table->foreignId('signature_id')->nullable()->after('map_connection_id')->constrained()->nullOnDelete();
        });
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->foreignId('signature_id')->nullable()->after('map_connection_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('map_camera_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signature_id');
        });
        Schema::table('map_cameras', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signature_id');
        });
    }
};
