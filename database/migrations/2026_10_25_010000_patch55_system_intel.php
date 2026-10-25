<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 55: intel per system (per map, like its notes): structures and POS, and a log of
 * everything added (structure changes, D-scans, fits, screenshots, notes) kept for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intel_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('solarsystem_id')->index();
            $table->string('kind', 32);
            $table->string('name', 120)->nullable();
            $table->string('owner', 120)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('state', 32)->default('online');
            $table->string('vulnerable_window', 60)->nullable();
            $table->timestamp('timer_at')->nullable();
            $table->text('fit')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('seen_at');
            $table->string('created_by_name', 120)->nullable();
            $table->string('updated_by_name', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['map_id', 'solarsystem_id']);
        });

        Schema::create('intel_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('solarsystem_id');
            $table->foreignId('intel_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->string('author_name', 120)->nullable();
            $table->timestamps();
            $table->index(['map_id', 'solarsystem_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intel_entries');
        Schema::dropIfExists('intel_structures');
    }
};
