<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 36 (chain memory): clears hide systems instead of deleting them.
     * A hidden system keeps its row (alias, signatures) so a reconnect within
     * 27 h brings it back as it was.
     */
    public function up(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('scanned_at');
            $table->index(['map_id', 'hidden_at']);
        });
    }

    public function down(): void
    {
        Schema::table('map_solarsystems', function (Blueprint $table): void {
            $table->dropIndex(['map_id', 'hidden_at']);
            $table->dropColumn('hidden_at');
        });
    }
};
