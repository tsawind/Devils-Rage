<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: when someone last looked at a jumped hole in game (picked a type, a
 * mass or life status, or pressed ✓ Checked in game). The map marks a pipe with
 * a purple "?" when nobody checked, jumped or changed it for 4 hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->timestamp('checked_at')->nullable()->after('lifetime_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn('checked_at');
        });
    }
};
