<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 39: the suggestions board (ideas, votes, comments, a status leaders set) and the
 * one-time reminders (each pilot / Discord member is sent the reminder once, logged).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devilsrage_suggestions')) {
            Schema::create('devilsrage_suggestions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('user_id')->index();
                $table->string('title', 120);
                $table->text('body')->nullable();
                $table->string('status', 16)->default('open');
                $table->string('status_note', 255)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('devilsrage_suggestion_votes')) {
            Schema::create('devilsrage_suggestion_votes', function (Blueprint $table): void {
                $table->foreignId('suggestion_id')->constrained('devilsrage_suggestions')->cascadeOnDelete();
                $table->unsignedInteger('user_id');
                $table->timestamps();

                $table->primary(['suggestion_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('devilsrage_suggestion_comments')) {
            Schema::create('devilsrage_suggestion_comments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('suggestion_id')->constrained('devilsrage_suggestions')->cascadeOnDelete();
                $table->unsignedInteger('user_id');
                $table->text('body');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('devilsrage_reminders')) {
            Schema::create('devilsrage_reminders', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 16);
                $table->string('target', 32);
                $table->string('name', 64)->nullable();
                $table->json('missing')->nullable();
                $table->boolean('sent')->default(false);
                $table->string('error', 255)->nullable();
                $table->unsignedInteger('sent_by')->nullable();
                $table->timestamps();

                $table->unique(['kind', 'target'], 'dr_reminders_once');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devilsrage_reminders');
        Schema::dropIfExists('devilsrage_suggestion_comments');
        Schema::dropIfExists('devilsrage_suggestion_votes');
        Schema::dropIfExists('devilsrage_suggestions');
    }
};
