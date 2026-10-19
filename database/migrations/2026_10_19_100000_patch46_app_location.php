<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 46: the desktop app reports a character's system from EVE's Local chat log the
 * moment it jumps. While that report is fresh, a slower ESI answer can't move it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_statuses', function (Blueprint $table): void {
            $table->timestamp('app_location_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('character_statuses', function (Blueprint $table): void {
            $table->dropColumn('app_location_at');
        });
    }
};
