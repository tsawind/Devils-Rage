<?php

declare(strict_types=1);

use App\Models\EsiScope;
use Illuminate\Database\Migrations\Migration;

/**
 * Patch 37: logging in (and "Grant all permissions") asks for every scope the mapper uses:
 * mail, open window, fleet, fittings, skills, implants and standings on top of the old ones.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array ADDED = [
        'esi-mail.send_mail.v1',
        'esi-ui.open_window.v1',
        'esi-fleets.read_fleet.v1',
        'esi-fleets.write_fleet.v1',
        'esi-fittings.read_fittings.v1',
        'esi-skills.read_skills.v1',
        'esi-clones.read_implants.v1',
        'esi-characters.read_standings.v1',
        'esi-corporations.read_standings.v1',
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
