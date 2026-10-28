<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\IntelStructure;
use App\Models\Killmail;
use App\Models\Map;
use App\Models\MapAccess;
use App\Support\SystemIntelligence;
use Illuminate\Support\Facades\DB;

/**
 * Patch 58: who lives in a system, who hunts there and who passed through, never counting us.
 */
function intelligenceTypes(): void
{
    $now = now();
    DB::table('categories')->insertOrIgnore([
        ['id' => 6, 'name' => 'Ship', 'created_at' => $now, 'updated_at' => $now],
        ['id' => 65, 'name' => 'Structure', 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('groups')->insertOrIgnore([
        ['id' => 941, 'name' => 'Industrial Command Ship', 'category_id' => 6, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 963, 'name' => 'Strategic Cruiser', 'category_id' => 6, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 1657, 'name' => 'Citadel', 'category_id' => 65, 'created_at' => $now, 'updated_at' => $now],
    ]);
    DB::table('types')->insertOrIgnore([
        ['id' => 28606, 'name' => 'Orca', 'group_id' => 941, 'published' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 32880, 'name' => 'Venture', 'group_id' => 25, 'published' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 12011, 'name' => 'Legion', 'group_id' => 963, 'published' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => 35832, 'name' => 'Astrahus', 'group_id' => 1657, 'published' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);
}

/**
 * @param  list<int>  $attackerCorps  one pilot per entry
 */
function intelligenceKill(int $id, int $daysAgo, int $victimCorp, int $victimShip, array $attackerCorps, int $hour = 20): void
{
    Killmail::query()->create([
        'id' => $id,
        'hash' => sha1((string) $id),
        'solarsystem_id' => 31000600,
        'time' => now()->subDays($daysAgo)->setTime($hour, 5),
        'data' => [
            'victim' => ['character_id' => 90000000 + $id, 'corporation_id' => $victimCorp, 'ship_type_id' => $victimShip],
            'attackers' => array_map(fn (int $corp, int $n): array => ['character_id' => 91000000 + $id * 10 + $n, 'corporation_id' => $corp, 'ship_type_id' => 12011], $attackerCorps, array_keys($attackerCorps)),
        ],
        'zkb' => [],
    ]);
}

it('tells residents, hunters, passers and us apart', function () {
    makeSolarsystem(31000600);
    intelligenceTypes();
    $map = Map::factory()->create();
    $ours = Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map))->create();
    $us = (int) $ours->corporation_id;
    $resident = Corporation::factory()->create(['name' => 'Holding Corp', 'ticker' => 'HOLD'])->id;
    $hunter = Corporation::factory()->create(['name' => 'Roamers'])->id;
    $passer = Corporation::factory()->create(['name' => 'Tourists'])->id;

    intelligenceKill(1, 1, $resident, 28606, [$us, $us]);
    intelligenceKill(2, 2, $resident, 32880, [$hunter, $hunter, $hunter]);
    intelligenceKill(3, 3, $passer, 12011, [$resident, $resident, $resident, $resident, $resident, $resident]);
    intelligenceKill(4, 4, $us, 12011, [$resident, $resident, $resident, $resident, $resident]);
    intelligenceKill(5, 5, $us, 12011, [$hunter, $hunter]);
    intelligenceKill(6, 6, $hunter, 12011, [$resident, $resident, $resident, $resident, $resident, $resident, $resident]);

    $intel = SystemIntelligence::for(31000600, $map);

    expect($intel['us'])->toBe(['kills' => 1, 'losses' => 2])
        ->and(array_column($intel['residents'], 'name'))->toBe(['Holding Corp'])
        ->and($intel['residents'][0]['role'])->toBe('resident')
        ->and($intel['residents'][0]['evidence'])->toContain('lost Orca')->toContain('lost Venture')
        ->and(array_column($intel['hunters'], 'name'))->toBe(['Roamers'])
        ->and(array_column($intel['passers'], 'name'))->toBe(['Tourists'])
        ->and($intel['basis'])->toBe('residents')
        ->and($intel['fleet']['max'])->toBe(7)
        ->and($intel['hours'][20])->toBeGreaterThan(0)
        ->and($intel['status']['tone'])->toBe('red')
        ->and(collect([...$intel['residents'], ...$intel['hunters'], ...$intel['passers']])->pluck('id'))->not->toContain($us);
});

it('calls a resident that lost its citadel and was not seen again evicted', function () {
    makeSolarsystem(31000600);
    intelligenceTypes();
    $map = Map::factory()->create();
    $gone = Corporation::factory()->create(['name' => 'Old Residents'])->id;
    $evictors = Corporation::factory()->create(['name' => 'Evictors'])->id;

    foreach ([20, 19, 18, 17] as $i => $daysAgo) {
        intelligenceKill(100 + $i, $daysAgo, $evictors, 12011, [$gone, $gone]);
    }
    intelligenceKill(110, 16, $gone, 35832, array_fill(0, 20, $evictors));

    $intel = SystemIntelligence::for(31000600, $map);

    expect($intel['residents'][0])->toMatchArray(['name' => 'Old Residents', 'role' => 'former'])
        ->and($intel['residents'][0]['evidence'])->toContain('lost its Astrahus')
        ->and($intel['status']['label'])->toBe('Evicted or empty');
});

it('counts the owner of a structure in our intel as living there', function () {
    makeSolarsystem(31000600);
    intelligenceTypes();
    $map = Map::factory()->create();
    $owner = Corporation::factory()->create(['name' => 'E85 Holding Services', 'ticker' => 'E85'])->id;
    intelligenceKill(200, 2, 98_000_999, 12011, [$owner, $owner]);
    IntelStructure::query()->create(['map_id' => $map->id, 'solarsystem_id' => 31000600, 'kind' => 'fortizar', 'name' => 'Main Barracks', 'owner' => 'E85 Holding Services', 'state' => 'online', 'seen_at' => now()]);

    $intel = SystemIntelligence::for(31000600, $map);

    expect($intel['residents'][0]['name'])->toBe('E85 Holding Services')
        ->and($intel['residents'][0]['evidence'])->toContain('owns a structure');
});

it('leaves NPC kills and NPC corporations out', function () {
    makeSolarsystem(31000600);
    intelligenceTypes();
    Killmail::query()->create([
        'id' => 300, 'hash' => sha1('300'), 'solarsystem_id' => 31000600, 'time' => now()->subDay(),
        'data' => ['victim' => ['character_id' => 1, 'corporation_id' => 1000125, 'ship_type_id' => 12011], 'attackers' => [['corporation_id' => 500021]]],
        'zkb' => ['npc' => true],
    ]);

    $intel = SystemIntelligence::for(31000600);

    expect($intel['kills'])->toBe(0)
        ->and($intel['residents'])->toBe([])
        ->and($intel['status']['label'])->toBe('Quiet');
});
