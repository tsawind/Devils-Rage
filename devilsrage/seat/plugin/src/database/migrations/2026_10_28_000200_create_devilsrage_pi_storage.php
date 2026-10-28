<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 59: what each storage facility / launchpad held every time the colony was opened in
     * game (SeAT keeps only the latest), so the PI screen can say whether a raw buffer is
     * filling up or draining. Kept 30 days.
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_storage')) {
            Schema::create('devilsrage_pi_storage', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->unsignedInteger('planet_id');
                $table->unsignedBigInteger('pin_id');
                $table->unsignedInteger('type_id');
                $table->unsignedBigInteger('amount');
                $table->dateTime('seen_at');
                $table->unique(['character_id', 'pin_id', 'type_id', 'seen_at'], 'dr_pi_storage_unique');
                $table->index(['character_id', 'planet_id']);
                $table->index('seen_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_storage');
    }
};
