<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Launch groups become tags: a character can be in several. Main / Second / Third are
     * shared by everyone and locked to one character per account; pilots add their own.
     * Characters get an order inside their account.
     */
    public function up(): void
    {
        // Safe to run again: an earlier version of this migration could stop halfway
        // (a too-long constraint name), and MySQL keeps whatever it already created.
        if (! Schema::hasColumn('devilsrage_account_characters', 'position')) {
            Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
                $table->unsignedSmallInteger('position')->default(0)->after('character_id');
            });
        }

        if (! Schema::hasTable('devilsrage_launch_groups')) {
            Schema::create('devilsrage_launch_groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id')->nullable()->index();
                $table->string('key', 10)->nullable()->unique();
                $table->string('name', 40);
                $table->boolean('locked')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['user_id', 'name']);
            });
        }

        // Nothing was ever stored in it by a half-finished run, so start it clean.
        Schema::dropIfExists('devilsrage_account_character_launch_group');
        Schema::create('devilsrage_account_character_launch_group', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_character_id');
            $table->unsignedBigInteger('launch_group_id');

            $table->primary(['account_character_id', 'launch_group_id'], 'dr_character_group_primary');
            $table->index('launch_group_id', 'dr_character_group_group');
            $table->foreign('account_character_id', 'dr_character_group_character_fk')
                ->references('id')->on('devilsrage_account_characters')->cascadeOnDelete();
            $table->foreign('launch_group_id', 'dr_character_group_group_fk')
                ->references('id')->on('devilsrage_launch_groups')->cascadeOnDelete();
        });

        $now = now();
        $builtIn = [];
        foreach (['main' => 'Main', 'second' => 'Second', 'third' => 'Third'] as $key => $name) {
            $existing = DB::table('devilsrage_launch_groups')->where('key', $key)->value('id');
            $builtIn[$key] = $existing ?? DB::table('devilsrage_launch_groups')->insertGetId([
                'key' => $key,
                'name' => $name,
                'locked' => true,
                'position' => count($builtIn),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Keep what pilots already set up: their single launch group becomes a tag, and
        // characters keep the order they were added in.
        $hasOldColumn = Schema::hasColumn('devilsrage_account_characters', 'launch_group');
        $rows = DB::table('devilsrage_account_characters')
            ->orderBy('account_id')
            ->orderBy('id')
            ->get($hasOldColumn ? ['id', 'account_id', 'launch_group'] : ['id', 'account_id']);
        $positions = [];
        foreach ($rows as $row) {
            $position = $positions[$row->account_id] = ($positions[$row->account_id] ?? -1) + 1;
            DB::table('devilsrage_account_characters')->where('id', $row->id)->update(['position' => $position]);

            if ($hasOldColumn && isset($builtIn[$row->launch_group])) {
                DB::table('devilsrage_account_character_launch_group')->insertOrIgnore([
                    'account_character_id' => $row->id,
                    'launch_group_id' => $builtIn[$row->launch_group],
                ]);
            }
        }

        if ($hasOldColumn) {
            Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
                $table->dropColumn('launch_group');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
            $table->string('launch_group', 10)->nullable();
        });

        $groups = DB::table('devilsrage_account_character_launch_group')
            ->join('devilsrage_launch_groups', 'devilsrage_launch_groups.id', '=', 'devilsrage_account_character_launch_group.launch_group_id')
            ->whereNotNull('devilsrage_launch_groups.key')
            ->orderBy('devilsrage_launch_groups.position')
            ->get(['account_character_id', 'key']);
        foreach ($groups->unique('account_character_id') as $group) {
            DB::table('devilsrage_account_characters')->where('id', $group->account_character_id)->update(['launch_group' => $group->key]);
        }

        Schema::dropIfExists('devilsrage_account_character_launch_group');
        Schema::dropIfExists('devilsrage_launch_groups');

        Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
