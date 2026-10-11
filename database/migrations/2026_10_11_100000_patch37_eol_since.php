<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: when a hole was first marked End of life and first marked Critical. The
 * latest collapse time counts from these (4 h / 1 h); a later check or re-mark only
 * moves the earliest collapse time (the newest look + 55 min). Existing marks start
 * from their last life update, the best the database knows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['map_connections', 'signatures'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->timestamp('eol_since')->nullable()->after('lifetime_updated_at');
                $blueprint->timestamp('critical_since')->nullable()->after('eol_since');
            });

            DB::table($table)->whereIn('lifetime', ['eol', 'critical'])->update(['eol_since' => DB::raw('lifetime_updated_at')]);
            DB::table($table)->where('lifetime', 'critical')->update(['critical_since' => DB::raw('lifetime_updated_at')]);
        }
    }

    public function down(): void
    {
        foreach (['map_connections', 'signatures'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['eol_since', 'critical_since']);
            });
        }
    }
};
