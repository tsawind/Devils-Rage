<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Character;
use App\Models\IntelEntry;
use App\Models\IntelStructure;
use App\Models\Killmail;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\User;
use App\Support\DscanSummary;
use App\Support\StructureScan;
use App\Support\SystemAssessment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * Patch 55: a system's intel: structures / POS with a log of every change, D-scans, fits,
 * screenshots, and who lives there from the kills the mapper keeps.
 */
function intelMember(Map $map, Permission $permission = Permission::Member): User
{
    $user = User::factory()->has(Character::factory(['name' => 'LuigiSquirrel', 'seat_main_name' => 'Tsawind'])->has(MapAccess::factory(['permission' => $permission])->for($map)))->create();
    $user->update(['preferred_character_id' => $user->characters()->value('id')]);

    return $user->fresh();
}

it('adds a structure, logs it, and changes to it are logged too', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000501);

    $added = postJson(route('map-solarsystems.intel.structures.store', $system), [
        'kind' => 'fortizar', 'name' => 'Yankee (Holding 418)', 'state' => 'online', 'vulnerable_window' => 'Sun 19:00 - 22:00',
    ])->assertCreated()->json('structure');

    putJson(route('map-solarsystems.intel.structures.update', [$system, $added['id']]), [
        'kind' => 'fortizar', 'name' => 'Yankee (Holding 418)', 'state' => 'armor_timer', 'timer_at' => '2026-10-26 21:14:00',
    ])->assertSuccessful();

    $structure = IntelStructure::query()->findOrFail($added['id']);
    expect($structure->state)->toBe('armor_timer')
        ->and($structure->created_by_name)->toBe('Tsawind');
    $log = IntelEntry::query()->where('intel_structure_id', $structure->id)->orderBy('id')->pluck('body')->all();
    expect($log[0])->toBe('Added Fortizar "Yankee (Holding 418)"')
        ->and($log[1])->toContain('changed')->toContain('state');

    delete(route('map-solarsystems.intel.structures.destroy', [$system, $structure->id]))->assertSuccessful();
    expect(IntelStructure::query()->find($structure->id))->toBeNull()
        ->and(IntelStructure::withTrashed()->find($structure->id))->not->toBeNull()
        ->and(IntelEntry::query()->where('intel_structure_id', $structure->id)->count())->toBe(3);
});

it('reads a pasted D-scan and keeps it in the log; a fit goes onto its structure', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000502);
    $structure = postJson(route('map-solarsystems.intel.structures.store', $system), ['kind' => 'astrahus', 'state' => 'online'])->json('structure');

    $dscan = "12011\tTsawind's Legion\tLegion\t1,200 km\n12011\tSomeone's Legion\tLegion\t3.1 AU\n35833\tYankee\tFortizar\t2,500 m\n";
    $entry = postJson(route('map-solarsystems.intel.entries.store', $system), ['kind' => 'dscan', 'body' => $dscan])->assertCreated()->json('entry');
    expect($entry['data']['objects'])->toBe(3)
        ->and($entry['data']['types'][0])->toBe(['type' => 'Legion', 'count' => 2])
        ->and($entry['data']['on_grid'])->toBe(2);

    postJson(route('map-solarsystems.intel.entries.store', $system), ['kind' => 'fit', 'body' => "Standup Warp Scrambler I\nStandup Stasis Webifier I", 'intel_structure_id' => $structure['id']])->assertCreated();
    expect(IntelStructure::query()->find($structure['id'])->fit)->toContain('Standup Warp Scrambler I');
});

it('stores a screenshot and shows it only to people who can see the map', function () {
    Storage::fake('local');
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000503);

    $entry = post(route('map-solarsystems.intel.screenshots.store', $system), ['image' => UploadedFile::fake()->image('fort.png', 400, 300), 'caption' => 'Fortizar fit'])
        ->assertCreated()->json('entry');
    expect($entry['image_url'])->not->toBeNull();
    get($entry['image_url'])->assertSuccessful();

    actingAs(User::factory()->create());
    get($entry['image_url'])->assertForbidden();
    getJson(route('map-solarsystems.intel.show', $system))->assertForbidden();
});

it('lets viewers read the intel but not change it', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map, Permission::Viewer));
    $system = placeMapSolarsystem($map, 31000504);

    getJson(route('map-solarsystems.intel.show', $system))->assertSuccessful()->assertJsonStructure(['structures', 'entries', 'assessment' => ['kills', 'hours', 'corporations', 'ships']]);
    postJson(route('map-solarsystems.intel.structures.store', $system), ['kind' => 'astrahus', 'state' => 'online'])->assertForbidden();
});

it('works out who lives in a system from its kills', function () {
    makeSolarsystem(31000505);
    foreach ([[20, 5], [21, 6], [21, 8], [3, 2]] as $i => [$hour, $attackers]) {
        Killmail::query()->create([
            'id' => 900000 + $i,
            'hash' => sha1((string) $i),
            'solarsystem_id' => 31000505,
            'time' => now()->subDays($i + 1)->setTime($hour, 10),
            'data' => [
                'victim' => ['corporation_id' => 98000010 + $i, 'ship_type_id' => 1],
                'attackers' => array_map(fn (int $n): array => ['corporation_id' => 98000001, 'ship_type_id' => 12011], range(1, $attackers)),
            ],
            'zkb' => [],
        ]);
    }

    $assessment = SystemAssessment::for(31000505);

    expect($assessment['kills'])->toBe(4)
        ->and($assessment['hours'][21])->toBe(2)
        ->and($assessment['peak'])->toBe(['from' => 19, 'to' => 22])
        ->and($assessment['fleet'])->toBe(['median' => 6, 'max' => 8])
        ->and($assessment['corporations'][0]['kills'])->toBe(4)
        ->and($assessment['corporations'][0]['days'])->toBe(4);
});

it('counts D-scan types from both EVE copy formats', function () {
    $withIds = DscanSummary::parse("12011\tA\tLegion\t5 km\n12011\tB\tLegion\t-\n");
    $withoutIds = DscanSummary::parse("A\tLegion\t5 km\nB\tLoki\t12.4 AU\nnot a scan line\n");
    expect($withIds['types'])->toBe([['type' => 'Legion', 'count' => 2]])
        ->and($withoutIds['objects'])->toBe(2)
        ->and($withoutIds['on_grid'])->toBe(1);
});

it('patch 57: reads structures from a D-scan and from overview rows', function () {
    $dscan = "35833\tJ145735 - Old-Daisy\tFortizar\t27.7 AU\n12011\tSomeone's Legion\tLegion\t3.1 AU\n47512\tThe Moreau\t'Moreau' Fortizar\t2,500 km\n16213\tTower\tAmarr Control Tower Large\t4.2 AU\n2233\tCustoms Office (J145735 V)\tCustoms Office\t5 AU";
    $overview = "27.7 AU\tJ145735 - Old-Daisy\tFortizar\t-\t-\t\t[TDVS]\n3.3 AU\tJ145735 - Star\tSun G5 (Pink)\t-\t-";

    expect(StructureScan::parse($dscan))->toBe([
        ['kind' => 'fortizar', 'type' => 'Fortizar', 'name' => 'J145735 - Old-Daisy', 'owner' => null, 'distance' => '27.7 AU'],
        ['kind' => 'fortizar', 'type' => "'Moreau' Fortizar", 'name' => 'The Moreau', 'owner' => null, 'distance' => '2,500 km'],
        ['kind' => 'pos', 'type' => 'Amarr Control Tower Large', 'name' => 'Tower', 'owner' => null, 'distance' => '4.2 AU'],
    ])->and(StructureScan::parse($overview))->toBe([
        ['kind' => 'fortizar', 'type' => 'Fortizar', 'name' => 'J145735 - Old-Daisy', 'owner' => 'TDVS', 'distance' => '27.7 AU'],
    ])->and(StructureScan::parse('J145735 - Old-Daisy'))->toBe([])
        ->and(StructureScan::parse("o7 fleet\nanyone got a Fortizar?"))->toBe([]);
});

it('patch 57: adds structures copied from EVE once, then marks them seen again', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000503);

    $first = postJson(route('map-solarsystems.intel.structures.import', $system), ['text' => "35833\tJ145735 - Old-Daisy\tFortizar\t27.7 AU"])
        ->assertCreated()->json();
    expect($first['added'])->toHaveCount(1)->and($first['updated'])->toBe([]);

    $second = postJson(route('map-solarsystems.intel.structures.import', $system), ['text' => "27.7 AU\tJ145735 - Old-Daisy\tFortizar\t-\t[TDVS]"])
        ->assertSuccessful()->json();
    expect($second['added'])->toBe([])->and($second['updated'])->toBe($first['added']);

    $structure = IntelStructure::query()->sole();
    expect($structure->kind)->toBe('fortizar')
        ->and($structure->name)->toBe('J145735 - Old-Daisy')
        ->and($structure->owner)->toBe('TDVS')
        ->and($structure->created_by_name)->toBe('Tsawind')
        ->and(IntelEntry::query()->where('intel_structure_id', $structure->id)->pluck('body')->all())
        ->toBe(['Added Fortizar "J145735 - Old-Daisy" (copied from EVE)', 'Fortizar "J145735 - Old-Daisy": seen again (copied from EVE)']);
});

it('patch 57: a pasted D-scan adds its structures to the system', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000504);

    postJson(route('map-solarsystems.intel.entries.store', $system), ['kind' => 'dscan', 'body' => "35832\tHome\tAstrahus\t1.2 AU\n12011\tA Legion\tLegion\t1,200 km"])->assertCreated();

    expect(IntelStructure::query()->pluck('kind', 'name')->all())->toBe(['Home' => 'astrahus']);
});

it('patch 57: only people who can edit the map import structures', function () {
    $map = Map::factory()->create();
    actingAs(intelMember($map, Permission::Viewer));
    $system = placeMapSolarsystem($map, 31000505);

    postJson(route('map-solarsystems.intel.structures.import', $system), ['text' => "35833\tX\tFortizar\t1 AU"])->assertForbidden();
    postJson(route('map-solarsystems.intel.structures.import', $system), [])->assertForbidden();
    expect(IntelStructure::query()->count())->toBe(0);
});

it('patch 57: deletes a D-scan or screenshot added by mistake, but keeps structure history', function () {
    Storage::fake('local');
    $map = Map::factory()->create();
    actingAs(intelMember($map));
    $system = placeMapSolarsystem($map, 31000506);

    $dscan = postJson(route('map-solarsystems.intel.entries.store', $system), ['kind' => 'dscan', 'body' => "35833\tX\tFortizar\t101 km"])->json('entry');
    $shot = post(route('map-solarsystems.intel.screenshots.store', $system), ['image' => UploadedFile::fake()->image('shot.png')])->json('entry');
    $path = IntelEntry::query()->findOrFail($shot['id'])->image_path;
    $history = IntelEntry::query()->where('kind', 'structure')->firstOrFail();

    delete(route('map-solarsystems.intel.entries.destroy', [$system, $dscan['id']]))->assertSuccessful();
    delete(route('map-solarsystems.intel.entries.destroy', [$system, $shot['id']]))->assertSuccessful();
    delete(route('map-solarsystems.intel.entries.destroy', [$system, $history->id]))->assertStatus(422);

    expect(IntelEntry::query()->find($dscan['id']))->toBeNull()
        ->and(IntelEntry::query()->find($history->id))->not->toBeNull();
    Storage::disk('local')->assertMissing((string) $path);
});

it('patch 57: viewers cannot delete intel', function () {
    $map = Map::factory()->create();
    $system = placeMapSolarsystem($map, 31000507);
    actingAs(intelMember($map));
    $note = postJson(route('map-solarsystems.intel.entries.store', $system), ['kind' => 'note', 'body' => 'hi'])->json('entry');

    actingAs(intelMember($map, Permission::Viewer));
    delete(route('map-solarsystems.intel.entries.destroy', [$system, $note['id']]))->assertForbidden();
    expect(IntelEntry::query()->find($note['id']))->not->toBeNull();
});
