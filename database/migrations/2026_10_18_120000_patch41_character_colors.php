<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 41: each character's colour (picked by its owner, seen by everyone): its tab, its pin
 * on the map cards, its ALT WINDOW strip and its pop-out. Empty = one handed out by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            if (! Schema::hasColumn('characters', 'map_color')) {
                $table->string('map_color', 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn('map_color'));
    }
};
