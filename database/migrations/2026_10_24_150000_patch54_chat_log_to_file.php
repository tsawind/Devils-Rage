<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 54: whether EVE's "Log Chat to File" is on for a character, as the desktop app last saw
 * it (an EVE window open for the character, and a Local chat log for it or not). Without it the
 * app can't move the character instantly or tell whose probe scan it was. Null = never seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->boolean('chat_log_to_file')->nullable();
            $table->timestamp('chat_log_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table): void {
            $table->dropColumn(['chat_log_to_file', 'chat_log_checked_at']);
        });
    }
};
