<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: rolling, done together.
 * - rolling_crews: each person's own crews (ordered characters; expendable ones; "can swap
 *   pilots" for juggling). Everyone above viewer sees and uses them.
 * - mass_sessions: "⚖ Adding mass…" on a hole: who, the goal, the crews, the plan (its steps
 *   tick off as the jump log sees them, and those jumps log the plan's masses).
 * - mass_observations: every status someone saw on a hole with the mass logged at that
 *   moment ("crit, made by the last jump" too): they narrow down how big the hole really is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rolling_crews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('owner_name')->nullable();
            $table->string('name', 60);
            $table->json('members');
            $table->boolean('can_swap')->default(false);
            $table->timestamps();
        });

        Schema::create('mass_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->foreignId('map_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('started_by_name')->nullable();
            $table->string('goal', 16)->default('watch');
            $table->json('crew_ids')->nullable();
            $table->boolean('only_our_jumps')->default(true);
            $table->boolean('hurry')->default(false);
            $table->unsignedBigInteger('home_map_solarsystem_id')->nullable();
            $table->json('plan')->nullable();
            $table->timestamp('plan_started_at')->nullable();
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('mass_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_connection_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedBigInteger('logged_mass');
            $table->unsignedBigInteger('flip_mass')->nullable();
            $table->string('flip_by')->nullable();
            $table->string('by_name')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mass_observations');
        Schema::dropIfExists('mass_sessions');
        Schema::dropIfExists('rolling_crews');
    }
};
