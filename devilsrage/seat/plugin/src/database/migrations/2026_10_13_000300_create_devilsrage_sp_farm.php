<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SP farming: which characters farm, on which training slot, and the corp-wide
     * numbers (PLEX per month, price overrides) leaders keep up to date.
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_sp_farm_characters')) {
            Schema::create('devilsrage_sp_farm_characters', function (Blueprint $table): void {
                $table->bigInteger('character_id')->primary();
                $table->unsignedInteger('user_id')->index();
                // omega = pays the account's Omega, mct = pays a Multiple Character Training slot, free = Omega is paid anyway
                $table->string('slot', 10)->default('omega');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('devilsrage_settings')) {
            Schema::create('devilsrage_settings', function (Blueprint $table): void {
                $table->string('key', 60)->primary();
                $table->string('value', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_settings');
        Schema::dropIfExists('devilsrage_sp_farm_characters');
    }
};
