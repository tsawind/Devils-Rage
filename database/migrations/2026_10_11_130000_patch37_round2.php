<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37 round 2:
 * - map_solarsystems.memory_links: the pipes a cleared system had to the rest of its
 *   cleared chain, so a reconnect brings the whole old chain back.
 * - map_connections.from_memory: a pipe of an old chain that came back with a reconnect,
 *   drawn faded until someone jumps, types, marks or checks it.
 * - maps.background_image_path / _mode: the map's default background (set by managers).
 * - map_user_settings.background_choice: map (default) / none / own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->boolean('from_memory')->default(false)->after('checked_note');
        });

        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->json('memory_links')->nullable();
        });

        Schema::table('maps', function (Blueprint $table): void {
            $table->string('background_image_path')->nullable();
            $table->string('background_image_mode')->nullable();
        });

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->string('background_choice', 8)->nullable()->after('background_image_mode');
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn('from_memory');
        });
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropColumn('memory_links');
        });
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn(['background_image_path', 'background_image_mode']);
        });
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->dropColumn('background_choice');
        });
    }
};
