<?php

declare(strict_types=1);

use App\Models\EsiScope;

it('patch 37: logging in asks for the fleet, mail, window, fitting, skill, implant and standing scopes', function () {
    $defaults = EsiScope::query()->where('is_default', true)->pluck('name')->all();

    expect($defaults)->toContain(
        'esi-mail.send_mail.v1',
        'esi-ui.open_window.v1',
        'esi-fleets.read_fleet.v1',
        'esi-fleets.write_fleet.v1',
        'esi-fittings.read_fittings.v1',
        'esi-skills.read_skills.v1',
        'esi-clones.read_implants.v1',
        'esi-characters.read_standings.v1',
        'esi-corporations.read_standings.v1',
    );
});
