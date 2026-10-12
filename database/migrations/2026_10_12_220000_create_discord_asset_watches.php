<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "/assets watch": DM a pilot when a new k-space exit on a map lands within a few
     * gate jumps of their stuff (asset locations come from the Devil's Rage SeAT plugin).
     */
    public function up(): void
    {
        Schema::create('discord_asset_watches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('max_jumps');
            $table->boolean('ships_only')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'map_id']);
        });

        // One DM per watch per exit placement, even when the job runs again.
        Schema::create('discord_asset_watch_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discord_asset_watch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_solarsystem_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['discord_asset_watch_id', 'map_solarsystem_id'], 'asset_watch_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_asset_watch_deliveries');
        Schema::dropIfExists('discord_asset_watches');
    }
};
