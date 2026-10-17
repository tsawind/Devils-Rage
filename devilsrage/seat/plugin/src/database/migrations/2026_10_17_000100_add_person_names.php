<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 39: the name a person goes by ("Tsa"), unique across SeAT, and how Discord
     * shows them ([TICKER] Main / [TICKER] Name / [TICKER] Name (Main)).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_account_settings', 'person_name')) {
            Schema::table('devilsrage_account_settings', function (Blueprint $table): void {
                $table->string('person_name', 32)->nullable()->unique('dr_person_name_unique');
                $table->string('discord_style', 8)->default('main');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_account_settings', function (Blueprint $table): void {
            $table->dropUnique('dr_person_name_unique');
            $table->dropColumn(['person_name', 'discord_style']);
        });
    }
};
