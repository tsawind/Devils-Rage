<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The desktop app's planet scan (remake): each planet's own sky (the stars and nebula behind
     * it, which tell the scanner which way the camera faces) and its bare map (the planet's own
     * colours), recorded once by anyone and shared with the corp. data is base64 (a sky is a few MB).
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_planet_maps')) {
            Schema::create('devilsrage_pi_planet_maps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('planet_id');
                $table->string('kind', 10);
                $table->longText('data');
                $table->unsignedInteger('bytes')->default(0);
                $table->float('coverage')->default(0);
                $table->text('meta')->nullable();
                $table->unsignedInteger('user_id');
                $table->unsignedBigInteger('character_id')->nullable();
                $table->timestamps();
                $table->unique(['planet_id', 'kind'], 'dr_pi_planet_maps_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_planet_maps');
    }
};
