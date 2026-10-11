<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37: viewer links. Members make links that show the map read only for up to 7 days;
 * managers can also make permanent ones and add or cut time on any link. A route link
 * (from a mailed route report) shows only that route, as it was when it was sent, for 24 h.
 * The old single share link becomes a permanent link with the same address.
 * Also: the SeAT main's character id, so a route report can be mailed to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_share_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('map_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('kind', 16)->default('map');
            $table->string('label', 80)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
            $table->index(['map_id', 'expires_at']);
        });

        foreach (DB::table('maps')->whereNotNull('share_token')->get(['id', 'share_token']) as $map) {
            DB::table('map_share_links')->insert([
                'map_id' => $map->id,
                'token' => $map->share_token,
                'kind' => 'map',
                'label' => 'Old share link',
                'expires_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('maps')->whereNotNull('share_token')->update(['share_token' => null]);

        Schema::table('characters', function (Blueprint $table): void {
            $table->unsignedBigInteger('seat_main_id')->nullable()->after('seat_main_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_share_links');
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn('seat_main_id');
        });
    }
};
