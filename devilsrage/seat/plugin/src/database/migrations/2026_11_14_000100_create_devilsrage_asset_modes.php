<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assets page plans: War / Being evicted for the whole corp (user_id null, set by a director)
     * and each member's own Vacation (directors see everyone's, to grab from their division or
     * contact them).
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_asset_modes')) {
            Schema::create('devilsrage_asset_modes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id')->nullable()->unique();
                $table->string('mode', 20);
                $table->unsignedTinyInteger('threat')->default(2);
                $table->timestamp('until')->nullable();
                $table->unsignedInteger('set_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_asset_modes');
    }
};
