<?php

declare(strict_types=1);

use App\Models\EsiScope;
use Illuminate\Database\Migrations\Migration;

/**
 * 20 Nov: logging in (and "Grant all permissions") also asks for structure names (the citadel a
 * character is docked in, and finding one by name) and jump fatigue (capitals and black ops).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array ADDED = [
        'esi-universe.read_structures.v1',
        'esi-search.search_structures.v1',
        'esi-characters.read_fatigue.v1',
    ];

    public function up(): void
    {
        foreach (self::ADDED as $name) {
            EsiScope::query()->updateOrCreate(['name' => $name], ['is_default' => true]);
        }
    }

    public function down(): void
    {
        EsiScope::query()->whereIn('name', self::ADDED)->update(['is_default' => false]);
    }
};
