<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: the 🧵 thread switch inside JUMPING HOT / COLD: entanglers on (a HIC threading
 * a hole). Logged jumps note it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('jumping_thread')->default(false);
        });
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->boolean('is_thread')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_user_settings', fn (Blueprint $table) => $table->dropColumn('jumping_thread'));
        Schema::table('map_connection_jumps', fn (Blueprint $table) => $table->dropColumn('is_thread'));
    }
};
