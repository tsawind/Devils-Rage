<?php

declare(strict_types=1);

use App\Events\Killmails\KillmailReceivedEvent;
use App\Models\Alliance;
use App\Models\Corporation;
use App\Models\Killmail;
use App\Models\Map;

/**
 * Patch 54: the map's kill marker shows who died and who killed them on hover, and links to the
 * kill on zKillboard: the broadcast carries the kill id and those names.
 */
it('tells the map the kill id, the victim corp and alliance, the attackers and the final blow corp', function () {
    makeSolarsystem(31002077);
    $victimCorp = Corporation::factory()->create(['name' => 'Victim Corp']);
    $victimAlliance = Alliance::factory()->create(['name' => 'Victim Alliance']);
    $killerCorp = Corporation::factory()->create(['name' => 'The Devil\'s Rage']);
    $killmail = Killmail::query()->create([
        'id' => 131234567,
        'hash' => sha1('kill'),
        'solarsystem_id' => 31002077,
        'time' => now(),
        'data' => [
            'victim' => ['character_id' => 1, 'corporation_id' => $victimCorp->id, 'alliance_id' => $victimAlliance->id, 'ship_type_id' => 0],
            'attackers' => [
                ['character_id' => 2, 'corporation_id' => $killerCorp->id, 'final_blow' => true],
                ['character_id' => 3, 'corporation_id' => $killerCorp->id, 'final_blow' => false],
            ],
        ],
        'zkb' => ['totalValue' => 25_000_000.0],
    ]);

    $payload = (new KillmailReceivedEvent(Map::factory()->create(), $killmail))->broadcastWith()['killmail'];

    expect($payload['id'])->toBe(131234567)
        ->and($payload['victim_corporation'])->toBe('Victim Corp')
        ->and($payload['victim_alliance'])->toBe('Victim Alliance')
        ->and($payload['attackers'])->toBe(2)
        ->and($payload['final_blow_corporation'])->toBe('The Devil\'s Rage')
        ->and($payload['value'])->toBe(25_000_000.0);
});
