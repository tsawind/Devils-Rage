<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PLEX in the account's PLEX vault: ESI can't see it, so the pilot types it in.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_accounts', 'plex_vault')) {
            Schema::table('devilsrage_accounts', function (Blueprint $table): void {
                $table->unsignedInteger('plex_vault')->nullable()->after('mct_reminded');
                $table->timestamp('plex_vault_at')->nullable()->after('plex_vault');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_accounts', function (Blueprint $table): void {
            $table->dropColumn(['plex_vault', 'plex_vault_at']);
        });
    }
};
