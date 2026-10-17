<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 39: people's names. Each character carries its person's name from SeAT ("Name you
 * go by", synced hourly); a guest who came through a share link picks the name people call
 * them in the mapper. Share links get an access level: viewer (read only) or member
 * ("Full access": map, scan, arm, watch; no map settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            if (! Schema::hasColumn('characters', 'seat_person_name')) {
                $table->string('seat_person_name', 32)->nullable();
            }
        });

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name', 32)->nullable();
            }
        });

        Schema::table('map_share_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('map_share_links', 'access')) {
                $table->string('access', 16)->default('viewer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn('seat_person_name'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('display_name'));
        Schema::table('map_share_links', fn (Blueprint $table) => $table->dropColumn('access'));
    }
};
