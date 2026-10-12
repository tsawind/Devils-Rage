<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Omega is per EVE account: the pilot types when it runs out; reminders go out
     * 7 days and 1 day before (omega_reminded remembers the last one sent).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_accounts', 'omega_until')) {
            Schema::table('devilsrage_accounts', function (Blueprint $table): void {
                $table->date('omega_until')->nullable()->after('nickname');
                $table->unsignedTinyInteger('omega_reminded')->nullable()->after('omega_until');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_accounts', function (Blueprint $table): void {
            $table->dropColumn(['omega_until', 'omega_reminded']);
        });
    }
};
