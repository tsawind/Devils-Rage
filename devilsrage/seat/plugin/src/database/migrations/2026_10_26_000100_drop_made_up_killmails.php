<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patch 55: SRP's own form saved a pasted battle link (zkillboard.com/related/<system>/<time>) as a
 * killmail with the system as its id and the time as its hash; SeAT then retried it against ESI
 * all day ("Invalid killmail_id and/or killmail_hash", 90+ failed jobs). Drops such rows: no real
 * hash and never loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $madeUp = DB::table('killmails')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('killmail_details')->whereColumn('killmail_details.killmail_id', 'killmails.killmail_id'))
            ->get(['killmail_id', 'killmail_hash'])
            ->filter(fn ($row): bool => preg_match('~^[0-9a-f]{40}$~i', (string) $row->killmail_hash) !== 1)
            ->pluck('killmail_id');

        if ($madeUp->isNotEmpty()) {
            DB::table('killmails')->whereIn('killmail_id', $madeUp->all())->delete();
        }
    }

    public function down(): void {}
};
