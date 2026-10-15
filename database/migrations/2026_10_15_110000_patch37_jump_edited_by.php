<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: the jump log shows who changed a jump by hand (✎ name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->string('edited_by_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->dropColumn('edited_by_name');
        });
    }
};
