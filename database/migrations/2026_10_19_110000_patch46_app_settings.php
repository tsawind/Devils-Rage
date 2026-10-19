<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 46: the desktop app's settings for everyone (message of the day, minimum version,
 * feature switches), set by directors on the App admin page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->string('updated_by_name', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
