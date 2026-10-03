<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 19: a per-person Clipboard switch (on by default: the mapper copies
 * bookmark names for you; off = it only shows them with a Copy button), and
 * Follow starts on for a new pilot like the other toolbar toggles. Existing
 * settings rows are not changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('clipboard_enabled')->default(true)->after('copy_bookmark_enabled');
            $table->boolean('follow_character_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->dropColumn('clipboard_enabled');
            $table->boolean('follow_character_enabled')->default(false)->change();
        });
    }
};
