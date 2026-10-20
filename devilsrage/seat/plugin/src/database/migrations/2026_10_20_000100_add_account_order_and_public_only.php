<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 49b: the pilot's own order of their accounts (dragged on Your accounts), and
     * characters logged in with public data only (proves they're yours, shares nothing else).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_accounts', 'position')) {
            Schema::table('devilsrage_accounts', function (Blueprint $table): void {
                $table->unsignedInteger('position')->default(0)->after('nickname');
            });
        }
        if (! Schema::hasColumn('devilsrage_account_characters', 'public_only')) {
            Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
                $table->boolean('public_only')->default(false)->after('external');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_accounts', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
        Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
            $table->dropColumn('public_only');
        });
    }
};
