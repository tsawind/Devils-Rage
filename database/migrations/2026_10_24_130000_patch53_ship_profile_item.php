<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 53: a fit belongs to one ship (EVE's item id), not every hull of that type: a new
 * Maelstrom bought after a loss doesn't log the old roller fit (Higgs and all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('ship_item_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ship_profiles', function (Blueprint $table): void {
            $table->dropColumn('ship_item_id');
        });
    }
};
