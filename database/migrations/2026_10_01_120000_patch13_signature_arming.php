<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 13: arm the hole you are about to jump. Who armed it, when (arms run
 * out after 15 minutes), their name for the map, and whether arming gave the
 * hole its number (then disarming frees the number again).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->foreignId('armed_by_user_id')->nullable()->after('alias')->constrained('users')->nullOnDelete();
            $table->string('armed_by_name')->nullable()->after('armed_by_user_id');
            $table->timestamp('armed_at')->nullable()->after('armed_by_name');
            $table->boolean('armed_claimed_alias')->default(false)->after('armed_at');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('armed_by_user_id');
            $table->dropColumn(['armed_by_name', 'armed_at', 'armed_claimed_alias']);
        });
    }
};
