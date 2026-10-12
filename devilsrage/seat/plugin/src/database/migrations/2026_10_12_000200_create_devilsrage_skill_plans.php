<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devilsrage_skill_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('devilsrage_skill_plan_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('skill_plan_id')->constrained('devilsrage_skill_plans')->cascadeOnDelete();
            $table->integer('skill_id');
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('position');

            $table->unique(['skill_plan_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_skill_plan_skills');
        Schema::dropIfExists('devilsrage_skill_plans');
    }
};
