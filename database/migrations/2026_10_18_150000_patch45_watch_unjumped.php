<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 45: watching a hole nobody has jumped yet (the dotted pipes). When it's jumped, the
 * new pipe is watched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->timestamp('mass_watched_at')->nullable();
            $table->string('mass_watched_by_name', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropColumn(['mass_watched_at', 'mass_watched_by_name']);
        });
    }
};
