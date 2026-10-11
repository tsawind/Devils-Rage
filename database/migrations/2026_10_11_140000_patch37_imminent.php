<?php

declare(strict_types=1);

use App\Enums\MassStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37:
 * - imminent_since: when a hole turned "Expired, closure imminent" (marked, or EOL! whose
 *   hour ran out); it leaves the map an hour later.
 * - New pilots' routing allows holes down to critical mass (life already defaults to
 *   EOL!, chain connections on, EVE-Scout off, shorter).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['map_connections', 'signatures'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('imminent_since')->nullable()->after('critical_since');
            });
        }

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->string('route_allow_mass_status')->default(MassStatus::Critical->value)->change();
        });
    }

    public function down(): void
    {
        foreach (['map_connections', 'signatures'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('imminent_since');
            });
        }

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->string('route_allow_mass_status')->default(MassStatus::Reduced->value)->change();
        });
    }
};
