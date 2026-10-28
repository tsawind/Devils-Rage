<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patch 59 (PI templates): EVE colony templates pilots keep on the PI screen and share with
     * everyone on our SeAT. body is exactly what EVE copied, so it pastes back unchanged.
     */
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_pi_templates')) {
            Schema::create('devilsrage_pi_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->string('name', 80);
                $table->string('note', 500)->nullable();
                $table->mediumText('body');
                $table->char('fingerprint', 40);
                $table->unsignedInteger('planet_type_id');
                $table->boolean('shared')->default(true);
                $table->timestamps();
                $table->unique(['user_id', 'fingerprint'], 'dr_pi_templates_unique');
                $table->index('shared');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_pi_templates');
    }
};
