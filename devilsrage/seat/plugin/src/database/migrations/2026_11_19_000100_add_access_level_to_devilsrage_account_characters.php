<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 74: how much each character shares: devil (everything), ally (a template), partial
     * (the pilot's own ticks) or public; and the ESI permissions ticked for ally / partial.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_account_characters', 'access_level')) {
            Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
                $table->string('access_level', 16)->nullable()->after('public_only');
                $table->json('access_scopes')->nullable()->after('access_level');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
            $table->dropColumn(['access_level', 'access_scopes']);
        });
    }
};
