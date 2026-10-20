<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 50 (PI): every extractor program we've seen (SeAT keeps only the current one), to
     * tell when a spot runs out; and each pilot's PI message choice (off unless they pick one).
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_runs')) {
            Schema::create('devilsrage_pi_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('character_id');
                $table->unsignedInteger('planet_id');
                $table->unsignedBigInteger('pin_id');
                $table->dateTime('install_time');
                $table->dateTime('expiry_time')->nullable();
                $table->unsignedInteger('product_type_id')->nullable();
                $table->unsignedInteger('cycle_time')->nullable();
                $table->unsignedInteger('qty_per_cycle')->nullable();
                $table->unsignedTinyInteger('heads')->default(0);
                $table->double('latitude')->default(0);
                $table->double('longitude')->default(0);
                $table->timestamps();
                $table->unique(['character_id', 'pin_id', 'install_time'], 'dr_pi_runs_unique');
                $table->index('pin_id');
            });
        }
        if (! Schema::hasColumn('devilsrage_account_settings', 'pi_alerts')) {
            Schema::table('devilsrage_account_settings', function (Blueprint $table): void {
                $table->string('pi_alerts', 8)->default('never');
                $table->unsignedTinyInteger('pi_alert_hour')->default(18);
                $table->string('pi_timezone', 64)->nullable();
                $table->date('pi_alert_sent')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_runs');
        Schema::table('devilsrage_account_settings', function (Blueprint $table): void {
            $table->dropColumn(['pi_alerts', 'pi_alert_hour', 'pi_timezone', 'pi_alert_sent']);
        });
    }
};
