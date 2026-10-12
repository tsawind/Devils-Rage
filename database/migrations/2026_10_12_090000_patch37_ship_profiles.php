<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 37:
 * - ship_profiles: each pilot's hull with its real cold (prop off) and hot (prop on) jump mass,
 *   from the fit SeAT knows (Higgs, plates, prop mod) or typed in; shared with the corp.
 * - map_user_settings.jumping_hot: the JUMPING HOT switch (all of a person's characters).
 * - map_connection_jumps.is_hot: whether a logged jump was hot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ship_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('character_id')->index();
            $table->unsignedBigInteger('ship_type_id');
            $table->unsignedBigInteger('cold_mass');
            $table->unsignedBigInteger('hot_mass');
            $table->boolean('higgs')->default(false);
            $table->unsignedSmallInteger('plates')->default(0);
            $table->unsignedBigInteger('prop_mass')->default(0);
            $table->string('source', 16)->default('seat');
            $table->string('note')->nullable();
            $table->string('updated_by_name')->nullable();
            $table->timestamp('fitted_at')->nullable();
            $table->timestamps();
            $table->unique(['character_id', 'ship_type_id']);
        });

        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('jumping_hot')->default(false);
        });

        Schema::table('map_connection_jumps', function (Blueprint $table): void {
            $table->boolean('is_hot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ship_profiles');
        Schema::table('map_user_settings', fn (Blueprint $table) => $table->dropColumn('jumping_hot'));
        Schema::table('map_connection_jumps', fn (Blueprint $table) => $table->dropColumn('is_hot'));
    }
};
