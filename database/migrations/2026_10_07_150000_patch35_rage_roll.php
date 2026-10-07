<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 35: a map can be "rage rolling" one system's static, with J-code targets.
     */
    public function up(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->foreignId('rage_roll_solarsystem_id')->nullable()->constrained('solarsystems')->nullOnDelete();
            $table->timestamp('rage_roll_started_at')->nullable();
            $table->string('rage_roll_started_by')->nullable();
            $table->json('rage_roll_targets')->nullable();
            $table->boolean('rage_roll_scanning')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('maps', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rage_roll_solarsystem_id');
            $table->dropColumn(['rage_roll_started_at', 'rage_roll_started_by', 'rage_roll_targets', 'rage_roll_scanning']);
        });
    }
};
