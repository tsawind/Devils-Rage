<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 62n: the share page's box a camera is, so sharing the same box again brings the same
     * camera back (the map's open windows, the wall and links keep working).
     */
    public function up(): void
    {
        Schema::table('map_cameras', function (Blueprint $table): void {
            $table->string('box_key', 40)->nullable()->after('path');
            $table->index(['map_id', 'user_id', 'box_key']);
        });
    }

    public function down(): void
    {
        Schema::table('map_cameras', function (Blueprint $table): void {
            $table->dropIndex(['map_id', 'user_id', 'box_key']);
            $table->dropColumn('box_key');
        });
    }
};
