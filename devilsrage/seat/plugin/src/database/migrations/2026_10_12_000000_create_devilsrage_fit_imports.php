<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devilsrage_fit_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('match_key')->unique();
            $table->bigInteger('fitting_id')->unsigned()->unique();
            $table->bigInteger('source_character_id');
            $table->bigInteger('source_fitting_id');
            $table->char('fingerprint', 40);
            $table->timestamps();

            // Deleting the fit in SeAT removes the link too (the next import brings it back
            // while it is still in the in-game library).
            $table->foreign('fitting_id')
                ->references('fitting_id')
                ->on('crypta_tech_seat_fittings')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_fit_imports');
    }
};
