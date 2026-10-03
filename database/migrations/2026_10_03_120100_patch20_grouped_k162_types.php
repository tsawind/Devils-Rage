<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patch 20: K162s narrowed down from Show Info ("unknown · large" = C2/3,
 * "dangerous" = C4/5, C1/2/3 in a C1) and a K162 frigate. They are their own
 * signature types (target class unknown, the range in `extra`), with the same
 * ids as resources/js/data/signatures.json.
 */
return new class extends Migration
{
    /** @var array<int, array{name: string, extra: string}> */
    private const array TYPES = [
        901 => ['name' => 'K162 - C1/2/3', 'extra' => 'C1/2/3'],
        902 => ['name' => 'K162 - C2/3', 'extra' => 'C2/3'],
        903 => ['name' => 'K162 - C4/5', 'extra' => 'C4/5'],
        904 => ['name' => 'K162 - Frigate', 'extra' => 'frigate'],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::TYPES as $id => $type) {
            DB::table('signature_types')->updateOrInsert(
                ['id' => $id],
                [
                    'name' => $type['name'],
                    'signature' => 'K162',
                    'signature_category_id' => 1,
                    'target_class' => 'unknown',
                    'extra' => $type['extra'],
                    'spawn_areas' => json_encode(['1', '2', '3', '4', '5', '6']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('signatures')->whereIn('signature_type_id', array_keys(self::TYPES))->update(['signature_type_id' => null]);
        DB::table('signature_types')->whereIn('id', array_keys(self::TYPES))->delete();
    }
};
