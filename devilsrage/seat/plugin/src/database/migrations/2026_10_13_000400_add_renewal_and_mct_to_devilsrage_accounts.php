<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Omega on a subscription (no reminders) and Multiple Character Training slots per account.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_accounts', 'omega_auto')) {
            Schema::table('devilsrage_accounts', function (Blueprint $table): void {
                $table->boolean('omega_auto')->default(false)->after('omega_reminded');
                $table->unsignedTinyInteger('mct_slots')->default(0)->after('omega_auto');
                $table->date('mct_until')->nullable()->after('mct_slots');
                $table->boolean('mct_auto')->default(false)->after('mct_until');
                $table->unsignedTinyInteger('mct_reminded')->nullable()->after('mct_auto');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_accounts', function (Blueprint $table): void {
            $table->dropColumn(['omega_auto', 'mct_slots', 'mct_until', 'mct_auto', 'mct_reminded']);
        });
    }
};
