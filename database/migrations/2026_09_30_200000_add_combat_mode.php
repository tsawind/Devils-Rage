<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Combat mode: chains started from a combat home and kept apart by color,
     * each person's own combat toggle, and when a system was last scanned (for
     * greying out dead ends).
     */
    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->string('combat_color', 16)->nullable()->after('pinned');
            $table->boolean('combat_home')->default(false)->after('combat_color');
            $table->boolean('combat_active')->default(false)->after('combat_home');
            $table->timestamp('scanned_at')->nullable()->after('combat_active');
        });

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('combat_mode')->default(false);
            $table->string('combat_color', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropColumn(['combat_color', 'combat_home', 'combat_active', 'scanned_at']);
        });

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->dropColumn(['combat_mode', 'combat_color']);
        });
    }
};
