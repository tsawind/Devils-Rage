<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patch 54: "K162 - Drifter", a K162 whose Show Info says "Destination: Drifter wormhole
 * systems" (rare). Same id as resources/js/data/signatures.json; listed last among the K162s.
 */
return new class extends Migration
{
    private const int ID = 905;

    public function up(): void
    {
        $now = now();
        DB::table('signature_types')->updateOrInsert(
            ['id' => self::ID],
            [
                'name' => 'K162 - Drifter',
                'signature' => 'K162',
                'signature_category_id' => 1,
                'target_class' => 'unknown',
                'extra' => 'drifter',
                'spawn_areas' => json_encode(['1', '2', '3', '4', '5', '6', 'h', 'l', 'n']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('signatures')->where('signature_type_id', self::ID)->update(['signature_type_id' => null]);
        DB::table('signature_types')->where('id', self::ID)->delete();
    }
};
