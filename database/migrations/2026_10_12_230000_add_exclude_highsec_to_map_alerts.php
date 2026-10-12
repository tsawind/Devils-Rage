<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kill alerts: skip kills in high-sec systems (wormhole groups rarely care about them).
     */
    public function up(): void
    {
        Schema::table('map_alerts', function (Blueprint $table): void {
            $table->boolean('exclude_highsec')->default(false)->after('xl_only');
        });
    }

    public function down(): void
    {
        Schema::table('map_alerts', function (Blueprint $table): void {
            $table->dropColumn('exclude_highsec');
        });
    }
};
