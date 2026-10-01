<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patch 12: a person's first visit to a map starts with the useful settings
 * on (location tracking, active tracking, prompt / preselect / suggest / copy
 * bookmark, compact signature list). Existing settings rows are not changed.
 *
 * Uniform system width is one switch per map: on for every map now, and the
 * default for new maps.
 */
return new class extends Migration
{
    private const array USER_SETTINGS = [
        'tracking_allowed',
        'is_tracking',
        'prompt_for_signature_enabled',
        'preselect_signature_enabled',
        'suggest_alias_enabled',
        'copy_bookmark_enabled',
        'compact_signature_list',
    ];

    public function up(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            foreach (self::USER_SETTINGS as $column) {
                $table->boolean($column)->default(true)->change();
            }
        });

        Schema::table('maps', function (Blueprint $table): void {
            $table->boolean('constant_width_enabled')->default(true)->change();
        });

        DB::table('maps')->update(['constant_width_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('map_user_settings', function (Blueprint $table): void {
            $table->boolean('tracking_allowed')->default(false)->change();
            $table->boolean('is_tracking')->default(true)->change();
            $table->boolean('prompt_for_signature_enabled')->default(true)->change();
            $table->boolean('preselect_signature_enabled')->default(false)->change();
            $table->boolean('suggest_alias_enabled')->default(false)->change();
            $table->boolean('copy_bookmark_enabled')->default(false)->change();
            $table->boolean('compact_signature_list')->default(false)->change();
        });

        Schema::table('maps', function (Blueprint $table): void {
            $table->boolean('constant_width_enabled')->default(false)->change();
        });
    }
};
