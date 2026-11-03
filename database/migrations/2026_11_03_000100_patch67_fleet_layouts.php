<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 67: saved fleet layouts (wings, squads, commanders, who goes where) the fleet boss
     * builds and invites in one go through EVE's fleet API. Shared ones are for the whole corp.
     */
    public function up(): void
    {
        Schema::create('fleet_layouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('shared')->default(false);
            $table->json('layout');
            $table->timestamps();
            $table->index(['shared', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_layouts');
    }
};
