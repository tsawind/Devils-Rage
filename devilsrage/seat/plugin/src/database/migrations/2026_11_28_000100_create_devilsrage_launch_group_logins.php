<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 28 Nov: accounts in a launch group at their login screen, with no character (the EVE
     * launcher's Character: None), e.g. a daily-login group of every account.
     */
    public function up(): void
    {
        if (Schema::hasTable('devilsrage_launch_group_logins')) {
            return;
        }
        Schema::create('devilsrage_launch_group_logins', function (Blueprint $table): void {
            $table->unsignedBigInteger('launch_group_id');
            $table->unsignedBigInteger('account_id');

            $table->primary(['launch_group_id', 'account_id'], 'dr_group_login_primary');
            $table->index('account_id', 'dr_group_login_account');
            $table->foreign('launch_group_id', 'dr_group_login_group_fk')
                ->references('id')->on('devilsrage_launch_groups')->cascadeOnDelete();
            $table->foreign('account_id', 'dr_group_login_account_fk')
                ->references('id')->on('devilsrage_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_launch_group_logins');
    }
};
