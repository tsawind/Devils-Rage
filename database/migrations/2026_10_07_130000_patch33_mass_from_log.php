<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 33: a status the jump log proved (reduced / critical), and holes the log says
 * must have rolled (flagged, never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->boolean('mass_status_from_log')->default(false)->after('mass_status_updated_at');
            $table->boolean('should_have_rolled')->default(false)->after('mass_status_from_log');
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn(['mass_status_from_log', 'should_have_rolled']);
        });
    }
};
