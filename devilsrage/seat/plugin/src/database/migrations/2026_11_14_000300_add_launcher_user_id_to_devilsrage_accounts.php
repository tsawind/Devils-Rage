<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The EVE account (CCP user ID) an account matches in the launcher's logs, for grouping characters. */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_accounts', 'launcher_user_id')) {
            Schema::table('devilsrage_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('launcher_user_id')->nullable()->after('nickname');
            });
        }
    }

    public function down(): void
    {
        Schema::table('devilsrage_accounts', function (Blueprint $table): void {
            $table->dropColumn('launcher_user_id');
        });
    }
};
