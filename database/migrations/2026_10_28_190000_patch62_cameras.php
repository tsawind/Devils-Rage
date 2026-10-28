<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 62: cameras (a member's EVE window streamed through the video relay) and what they
     * saw on a hole (possible jumps, with a short clip).
     */
    public function up(): void
    {
        Schema::create('map_cameras', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('character_id')->nullable();
            $table->string('owner_name', 80)->nullable();
            $table->string('character_name', 80)->nullable();
            $table->string('name', 80);
            $table->string('kind', 16)->default('hole');
            $table->foreignId('map_solarsystem_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('map_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 48)->unique();
            $table->string('quality', 16)->default('1080p10');
            $table->json('guest_share_link_ids')->nullable();
            $table->string('status', 12)->default('starting');
            $table->boolean('detect')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['map_id', 'status']);
        });

        Schema::create('map_camera_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_camera_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->unsignedSmallInteger('strength')->default(0);
            $table->string('clip_path')->nullable();
            $table->string('still_path')->nullable();
            $table->string('state', 12)->default('new');
            $table->string('ship_class', 16)->nullable();
            $table->string('side', 12)->nullable();
            $table->foreignId('map_connection_jump_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('happened_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['map_id', 'happened_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_camera_events');
        Schema::dropIfExists('map_cameras');
    }
};
