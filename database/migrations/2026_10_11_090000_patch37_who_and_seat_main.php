<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: who did what on a hole (the pipe details box) and each character's main from SeAT.
 *
 * - signatures: who added it, who set its type and when, and the last "looked at it in game"
 *   (a status picked or ✓ Checked in game) for unjumped holes.
 * - map_connections: who set the mass status, and who last looked at the hole and how.
 * - characters: the main character SeAT has for this character (synced hourly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->string('created_by_name')->nullable()->after('updated_at');
            $table->string('type_set_by_name')->nullable()->after('created_by_name');
            $table->timestamp('type_set_at')->nullable()->after('type_set_by_name');
            $table->timestamp('checked_at')->nullable()->after('type_set_at');
            $table->string('checked_by_name')->nullable()->after('checked_at');
            $table->string('checked_note', 40)->nullable()->after('checked_by_name');
        });

        Schema::table('map_connections', function (Blueprint $table): void {
            $table->string('mass_status_set_by_name')->nullable()->after('mass_status_updated_at');
            $table->string('checked_by_name')->nullable()->after('checked_at');
            $table->string('checked_note', 40)->nullable()->after('checked_by_name');
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->string('seat_main_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropColumn(['created_by_name', 'type_set_by_name', 'type_set_at', 'checked_at', 'checked_by_name', 'checked_note']);
        });

        Schema::table('map_connections', function (Blueprint $table): void {
            $table->dropColumn(['mass_status_set_by_name', 'checked_by_name', 'checked_note']);
        });

        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('seat_main_name');
        });
    }
};
