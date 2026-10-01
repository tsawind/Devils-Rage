<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 12: combat lanes sit side by side in the order the chains were started
 * (combat_started_at on the combat home), and a system kept when its chain was
 * cleared remembers the chain it came from ("was Red").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->timestamp('combat_started_at')->nullable()->after('combat_active');
            $table->string('combat_previous_color', 16)->nullable()->after('combat_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropColumn(['combat_started_at', 'combat_previous_color']);
        });
    }
};
