<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * HD Caps: the characters who fly the corp's dreads / FAX in a home-defence fight, apart from
     * HD (the subcap fleet). A character can be both.
     */
    public function up(): void
    {
        if (! DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', 'hd-caps')->exists()) {
            $hd = DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', 'hd')->value('position');
            DB::table('devilsrage_launch_groups')->insert([
                'user_id' => null, 'key' => 'hd-caps', 'name' => 'HD Caps', 'locked' => false,
                'position' => $hd !== null ? (int) $hd : (int) DB::table('devilsrage_launch_groups')->whereNull('user_id')->max('position') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', 'hd-caps')->delete();
    }
};
