<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Roles for the Assets page, ticked per character on Your accounts (shared, unlocked
     * launch groups everyone sees): HD (home defence), Coffins (sit in a hauler, stay logged
     * out), Can coffin (non-combat characters that can board one). Also usable as pedal groups.
     */
    public function up(): void
    {
        $position = (int) DB::table('devilsrage_launch_groups')->whereNull('user_id')->max('position');
        foreach ([['hd', 'HD'], ['coffin', 'Coffins'], ['can-coffin', 'Can coffin']] as [$key, $name]) {
            if (! DB::table('devilsrage_launch_groups')->whereNull('user_id')->where('key', $key)->exists()) {
                DB::table('devilsrage_launch_groups')->insert([
                    'user_id' => null, 'key' => $key, 'name' => $name, 'locked' => false, 'position' => ++$position,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('devilsrage_launch_groups')->whereNull('user_id')->whereIn('key', ['hd', 'coffin', 'can-coffin'])->delete();
    }
};
