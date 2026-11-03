<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 71 (PI video scan): one whole-planet heat map per planet and resource, painted by the
     * desktop app while a pilot rotates the planet. Shared with the corp (the corp map's overlay).
     * cells: 360 × 180 bytes (see Pi\PiScan). A rescan merges into the row.
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_scans')) {
            Schema::create('devilsrage_pi_scans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('planet_id');
                $table->string('resource', 60);
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('character_id')->nullable();
                $table->binary('cells');
                $table->float('coverage')->default(0);
                $table->timestamps();
                $table->unique(['planet_id', 'resource'], 'dr_pi_scans_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_scans');
    }
};
