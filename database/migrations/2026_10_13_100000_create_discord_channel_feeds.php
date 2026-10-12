<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channel feeds: the bot posts new chain exits (per map) or a daily structure fuel
     * summary into a Discord channel.
     */
    public function up(): void
    {
        if (Schema::hasTable('discord_channel_feeds')) {
            return;
        }

        Schema::create('discord_channel_feeds', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->foreignId('map_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('discord_guild_id', 32);
            $table->string('discord_channel_id', 32);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'map_id'], 'discord_channel_feeds_kind_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_channel_feeds');
    }
};
