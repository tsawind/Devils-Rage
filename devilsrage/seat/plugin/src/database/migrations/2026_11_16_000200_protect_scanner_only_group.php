<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Scanner only" becomes a role group like HD / Coffins: it can't be deleted, and the
     * Assets page never makes a scanner-only character a coffin unless it's ticked Coffins.
     */
    public function up(): void
    {
        if (DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', 'scanner')->exists()) {
            return;
        }
        $existing = DB::table('devilsrage_launch_groups')->whereNull('user_id')->whereNull('key')->whereRaw('lower(name) = ?', ['scanner only'])->value('id');
        if ($existing !== null) {
            DB::table('devilsrage_launch_groups')->where('id', $existing)->update(['key' => 'scanner', 'updated_at' => now()]);

            return;
        }
        DB::table('devilsrage_launch_groups')->insert([
            'user_id' => null, 'key' => 'scanner', 'name' => 'Scanner only', 'locked' => false,
            'position' => (int) DB::table('devilsrage_launch_groups')->whereNull('user_id')->max('position') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', 'scanner')->update(['key' => null]);
    }
};
