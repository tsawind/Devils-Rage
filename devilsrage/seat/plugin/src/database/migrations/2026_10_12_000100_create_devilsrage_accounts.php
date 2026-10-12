<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An EVE account as the pilot names it (a nickname, never the real login name).
        Schema::create('devilsrage_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->index();
            $table->string('nickname', 32);
            $table->timestamps();

            $table->unique(['user_id', 'nickname']);
        });

        // Up to three characters per account; launch group main / second / third.
        Schema::create('devilsrage_account_characters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('devilsrage_accounts')->cascadeOnDelete();
            $table->bigInteger('character_id')->unique();
            $table->string('launch_group', 10)->nullable();
            $table->timestamps();
        });

        // Whether admins and directors may see the pilot's account layout (default yes).
        Schema::create('devilsrage_account_settings', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->boolean('share')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_account_settings');
        Schema::dropIfExists('devilsrage_account_characters');
        Schema::dropIfExists('devilsrage_accounts');
    }
};
