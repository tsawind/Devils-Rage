<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 57: the rage roll timer. When the static collapsed (to the second, from the
     * collapsing pilot's jump) and who collapsed it.
     */
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->timestamp('rage_roll_collapsed_at')->nullable()->after('rage_roll_scanning');
            $table->string('rage_roll_collapsed_by')->nullable()->after('rage_roll_collapsed_at');
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropColumn(['rage_roll_collapsed_at', 'rage_roll_collapsed_by']);
        });
    }
};
