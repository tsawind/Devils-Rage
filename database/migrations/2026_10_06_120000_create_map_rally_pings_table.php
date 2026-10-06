<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 30: a log of rally pings (form up, rally moved, stand down) for the map's Discord settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_rally_pings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('character_name')->nullable();
            $table->string('kind', 20);
            $table->string('title');
            $table->string('channel');
            $table->string('mention')->nullable();
            $table->timestamps();

            $table->index(['map_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_rally_pings');
    }
};
