<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_alerts', function (Blueprint $table): void {
            $table->boolean('xl_only')->default(false)->after('include_highsec');
        });
    }

    public function down(): void
    {
        Schema::table('map_alerts', function (Blueprint $table): void {
            $table->dropColumn('xl_only');
        });
    }
};
