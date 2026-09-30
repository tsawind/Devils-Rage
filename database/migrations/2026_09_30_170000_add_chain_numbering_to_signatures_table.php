<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chain numbering: a wormhole signature's locked chain alias (e.g. "12"),
     * and whether it is the system's static or a wandering hole.
     */
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->string('alias', 32)->nullable()->after('signature_id');
            $table->boolean('is_static')->default(false)->after('alias');
            $table->boolean('is_wandering')->default(false)->after('is_static');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table): void {
            $table->dropColumn(['alias', 'is_static', 'is_wandering']);
        });
    }
};
