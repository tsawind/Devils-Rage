<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: a ship profile keeps its fit (for the hover) and every jump mass it can make:
 * cold, hot with each prop mod (a carrier's 10,000MN AB or 50,000MN MWD), and a HIC's
 * entanglers (1, 2, 3… active, "tiny" / "thread it").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('entanglers')->default(0)->after('plates');
            $table->json('modules')->nullable()->after('prop_mass');
            $table->json('options')->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->dropColumn(['entanglers', 'modules', 'options']);
        });
    }
};
