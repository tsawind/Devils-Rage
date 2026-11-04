<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every scan save kept with its date (devilsrage_pi_scans holds only the merged latest), so
     * depletion and regrowth can be watched over time. The scans saved before this start the history.
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_scan_snapshots')) {
            Schema::create('devilsrage_pi_scan_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('planet_id');
                $table->string('resource', 60);
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('character_id')->nullable();
                $table->binary('cells');
                $table->float('coverage')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->index(['planet_id', 'resource', 'created_at'], 'dr_pi_scan_snap_lookup');
            });
            if (Schema::hasTable('devilsrage_pi_scans')) {
                foreach (DB::table('devilsrage_pi_scans')->get() as $scan) {
                    DB::table('devilsrage_pi_scan_snapshots')->insert([
                        'planet_id' => $scan->planet_id, 'resource' => $scan->resource, 'user_id' => $scan->user_id,
                        'character_id' => $scan->character_id, 'cells' => $scan->cells, 'coverage' => $scan->coverage,
                        'created_at' => $scan->updated_at,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_scan_snapshots');
    }
};
