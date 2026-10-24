<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 52: the hole size a mass session worked out ("only our jumps"), kept on the pipe while
 * the session runs and after "Done massing" / "Done and watching". What's left = size − every
 * logged jump. A later mark only narrows it; one that contradicts it asks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('kept_size_low')->nullable();
            $table->unsignedBigInteger('kept_size_high')->nullable();
            $table->string('kept_size_from', 10)->nullable();
            $table->timestamp('kept_size_at')->nullable();
            $table->timestamp('kept_ack_at')->nullable();
        });
        Schema::table('mass_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('nominal_mass')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn(['kept_size_low', 'kept_size_high', 'kept_size_from', 'kept_size_at', 'kept_ack_at']);
        });
        Schema::table('mass_sessions', function (Blueprint $table): void {
            $table->dropColumn('nominal_mass');
        });
    }
};
