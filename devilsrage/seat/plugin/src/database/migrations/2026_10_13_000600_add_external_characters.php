<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Characters a pilot puts in an account without registering them on SeAT (public
     * data only), and the corp fits the pilot says they can fly.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('devilsrage_account_characters', 'external')) {
            Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
                $table->boolean('external')->default(false)->after('position');
                $table->string('external_name', 64)->nullable()->after('external');
                $table->string('external_corporation', 64)->nullable()->after('external_name');
                $table->string('external_alliance', 64)->nullable()->after('external_corporation');
            });
        }

        if (! Schema::hasTable('devilsrage_external_fits')) {
            Schema::create('devilsrage_external_fits', function (Blueprint $table): void {
                $table->unsignedBigInteger('account_character_id');
                $table->unsignedBigInteger('fitting_id');
                $table->primary(['account_character_id', 'fitting_id'], 'dr_external_fits_pk');
                $table->foreign('account_character_id', 'dr_external_fits_character_fk')
                    ->references('id')->on('devilsrage_account_characters')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_external_fits');
        Schema::table('devilsrage_account_characters', function (Blueprint $table): void {
            $table->dropColumn(['external', 'external_name', 'external_corporation', 'external_alliance']);
        });
    }
};
