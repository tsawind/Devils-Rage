<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 20 Nov: names of the player structures (citadels, engineering complexes) pilots dock in, read
 * from EVE with the Structure names permission of a pilot who can dock there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structure_names', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->unsignedInteger('type_id')->nullable();
            $table->unsignedInteger('solarsystem_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_names');
    }
};
