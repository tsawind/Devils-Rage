<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: "Add mass…" can take mass off (a typed negative amount, e.g. a jump logged twice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->bigInteger('mass')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->unsignedBigInteger('mass')->default(0)->change();
        });
    }
};
