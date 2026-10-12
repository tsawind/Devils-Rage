<?php

declare(strict_types=1);

use App\Actions\Discord\CheckCanFlyAction;
use App\Actions\Discord\ListAssetsNearChainAction;
use App\Actions\Discord\ListCapitalReachAction;
use App\Actions\Discord\ManageAssetWatchAction;
use App\Actions\Discord\SeatMessages;
use App\Actions\Discord\ShowSeatFitAction;
use App\Actions\MapAlerts\RecheckCapitalAlertsAction;
use App\Enums\JumpShipType;
use App\Enums\Permission;
use App\Enums\ShipSize;
use App\Jobs\MapAlerts\EvaluateAssetWatchesJob;
use App\Jobs\MapAlerts\EvaluateMapAlertsJob;
use App\Models\Character;
use App\Models\DiscordAccount;
use App\Models\DiscordAssetWatch;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapAlert;
use App\Models\MapConnection;
use App\Models\MapSolarsystem;
use App\Models\MapWebhook;
use App\Services\Discord\Commands\CommandRegistry;
use App\Services\Discord\Commands\HelpCommand;
use App\Services\JumpRange\JumpRangeCalculator;
use App\Services\Routing\CapitalPath;
use App\Services\Seat\AssetProximity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'services.seat_bot.url' => 'https://seat.test',
        'services.seat_bot.key' => str_repeat('k', 40),
        'services.discord.bot_token' => 'bot-token',
    ]);
});

function seatBotAccount(Map $map, string $discordUserId = '4242'): DiscordAccount
{
    $user = App\Models\User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();

    return DiscordAccount::factory()->for($user)->create(['discord_user_id' => $discordUserId])->load('user.characters');
}

function seatBotPlace(Map $map, int $solarsystemId): MapSolarsystem
{
    return MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => $solarsystemId]);
}

function seatBotLink(Map $map, MapSolarsystem $from, MapSolarsystem $to, ShipSize $size): MapConnection
{
    return MapConnection::factory()->create([
        'map_id' => $map->id,
        'from_map_solarsystem_id' => $from->id,
        'to_map_solarsystem_id' => $to->id,
        'ship_size' => $size,
    ]);
}

function seatBotLy(float $ly): float
{
    return $ly * JumpRangeCalculator::METERS_PER_LIGHTYEAR;
}

/**
 * A real stargate pair from the generated static data: [exit, one jump away].
 *
 * @return array{0: int, 1: int}
 */
function seatBotGatePair(): array
{
    /** @var array<int|string, int[]> $raw */
    $raw = json_decode(file_get_contents(resource_path('static/connections.json')), true);
    $origin = (int) array_key_first($raw);

    return [$origin, (int) $raw[$origin][0]];
}

it('lists every command in /help', function () {
    $names = collect(app(CommandRegistry::class)->all())
        ->map(fn ($command): string => $command->definition()->name())
        ->reject(fn (string $name): bool => $name === 'help');

    foreach ($names as $name) {
        expect(app(HelpCommand::class)->text(null))->toContain('`/'.$name);
    }
});

it('knows which jump ships may enter high-sec', function (JumpShipType $type, bool $allowed) {
    expect($type->canEnterHighsec())->toBe($allowed);
})->with([
    'carrier' => [JumpShipType::Carrier, false],
    'dreadnought' => [JumpShipType::Dreadnought, false],
    'titan' => [JumpShipType::Titan, false],
    'rorqual' => [JumpShipType::Rorqual, false],
    'jump freighter' => [JumpShipType::JumpFreighter, true],
    'black ops' => [JumpShipType::BlackOps, true],
]);

it('reaches only through holes marked XL from home', function () {
    Queue::fake();
    $home = makeSolarsystem(31009301, -1.0, 'wh');
    $middle = makeSolarsystem(31009302, -1.0, 'wh');
    $exit = makeSolarsystem(30009303, -0.2, 'eve');
    $map = Map::factory()->create();
    $homePlacement = seatBotPlace($map, $home);
    $middlePlacement = seatBotPlace($map, $middle);
    $exitPlacement = seatBotPlace($map, $exit);
    $map->update(['home_solarsystem_id' => $home]);
    seatBotLink($map, $homePlacement, $middlePlacement, ShipSize::ExtraLarge);
    $last = seatBotLink($map, $middlePlacement, $exitPlacement, ShipSize::Large);

    expect(app(CapitalPath::class)->reachableFromHome($map->refresh()))->toHaveKeys([$home, $middle])
        ->not->toHaveKey($exit);

    $last->update(['ship_size' => ShipSize::ExtraLarge]);

    expect(app(CapitalPath::class)->reachableFromHome($map->refresh()))->toHaveKey($exit);
});

it('has no capital path on a map without a home system', function () {
    expect(app(CapitalPath::class)->reachableFromHome(Map::factory()->create(['home_solarsystem_id' => null])))->toBeNull();
});

it('fires an XL-only jump range alert only once the whole path is XL', function () {
    Http::fake();
    Queue::fake();
    $staging = makeSolarsystem(30009311, -0.3, 'eve');
    $home = makeSolarsystem(31009312, -1.0, 'wh');
    $exit = makeSolarsystem(30009313, -0.1, 'eve', seatBotLy(2.0));
    $map = Map::factory()->create();
    $homePlacement = seatBotPlace($map, $home);
    $exitPlacement = seatBotPlace($map, $exit);
    $map->update(['home_solarsystem_id' => $home]);
    $hole = seatBotLink($map, $homePlacement, $exitPlacement, ShipSize::Large);
    MapAlert::factory()->jumpRange()->create([
        'map_id' => $map->id,
        'map_webhook_id' => MapWebhook::factory()->for($map)->create()->id,
        'target_solarsystem_id' => $staging,
        'xl_only' => true,
    ]);

    app()->call([new EvaluateMapAlertsJob($exitPlacement->id), 'handle']);
    Http::assertNothingSent();

    $hole->update(['ship_size' => ShipSize::ExtraLarge]);
    app()->call([new EvaluateMapAlertsJob($exitPlacement->id), 'handle']);
    Http::assertSentCount(1);
});

it('re-checks k-space systems when a hole turns XL and an XL-only alert exists', function () {
    Queue::fake();
    $home = makeSolarsystem(31009321, -1.0, 'wh');
    $exit = makeSolarsystem(30009322, -0.1, 'eve');
    $map = Map::factory()->create();
    $homePlacement = seatBotPlace($map, $home);
    $exitPlacement = seatBotPlace($map, $exit);
    MapAlert::factory()->jumpRange()->create(['map_id' => $map->id, 'target_solarsystem_id' => $exit, 'xl_only' => true]);

    seatBotLink($map, $homePlacement, $exitPlacement, ShipSize::ExtraLarge);

    Queue::assertPushed(EvaluateMapAlertsJob::class, fn (EvaluateMapAlertsJob $job): bool => $job->map_solarsystem_id === $exitPlacement->id);
    Queue::assertNotPushed(EvaluateMapAlertsJob::class, fn (EvaluateMapAlertsJob $job): bool => $job->map_solarsystem_id === $homePlacement->id);
});

it('does not re-check anything without XL-only alerts', function () {
    Queue::fake();
    $map = Map::factory()->create();
    seatBotPlace($map, makeSolarsystem(30009331, -0.1, 'eve'));

    app(RecheckCapitalAlertsAction::class)->handle($map->id);

    Queue::assertNotPushed(EvaluateMapAlertsJob::class);
});

it('lists which jump ships can reach a chain exit', function () {
    $staging = makeSolarsystem(30009341, -0.3, 'eve');
    $near = makeSolarsystem(30009342, -0.1, 'eve', seatBotLy(3.0));
    $highsec = makeSolarsystem(30009343, 0.8, 'eve', seatBotLy(1.0));
    $map = Map::factory()->create(['name' => 'Daisy']);
    seatBotPlace($map, $near);
    seatBotPlace($map, $highsec);
    $account = seatBotAccount($map);
    Http::fake(['seat.test/devilsrage/api/discord/4242/jump-ships*' => Http::response(['ships' => [
        ['character' => 'Kaelen Voss', 'ship' => 'Naglfar', 'jump_class' => 'dreadnought', 'system_id' => $staging, 'jdc' => 5],
    ]])]);

    $reply = app(ListCapitalReachAction::class)->handle($account, $map->id);

    expect($reply)->toContain('**Naglfar** · Kaelen Voss · JDC 5 · in Test System '.$staging)
        ->toContain('**Test System '.$near.'** 3.0 ly (not all XL)')
        ->not->toContain('Test System '.$highsec);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-DR-Key', str_repeat('k', 40)));
});

it('tells pilots to link SeAT when SeAT does not know them', function () {
    $map = Map::factory()->create();
    seatBotPlace($map, makeSolarsystem(30009351, -0.1, 'eve'));
    $account = seatBotAccount($map);
    Http::fake(['seat.test/*' => Http::response(['error' => 'not_linked'], 404)]);

    expect(app(ListCapitalReachAction::class)->handle($account, $map->id))->toBe(SeatMessages::NOT_LINKED);
});

it('explains when SeAT is not connected to the bot', function () {
    config(['services.seat_bot.key' => null]);
    Http::fake();
    $map = Map::factory()->create();
    seatBotPlace($map, makeSolarsystem(30009361, -0.1, 'eve'));
    $account = seatBotAccount($map);

    expect(app(ListCapitalReachAction::class)->handle($account, $map->id))->toStartWith('SeAT could not be asked: SeAT is not connected');
    Http::assertNothingSent();
});

it('refuses maps the pilot cannot see', function () {
    $map = Map::factory()->create();
    $account = seatBotAccount(Map::factory()->create());
    Http::fake();

    expect(app(ListCapitalReachAction::class)->handle($account, $map->id))->toBe('That map is unavailable.')
        ->and(app(ListAssetsNearChainAction::class)->handle($account, $map->id, 5, false))->toBe('That map is unavailable.')
        ->and(app(ManageAssetWatchAction::class)->watch($account, $map->id, 5, false))->toBe('That map is unavailable.');
    Http::assertNothingSent();
});

it('lists assets within gate jumps of the chain exits', function () {
    [$exit, $neighbour] = seatBotGatePair();
    makeSolarsystem($exit, -0.1, 'eve');
    makeSolarsystem($neighbour, -0.1, 'eve');
    $map = Map::factory()->create(['name' => 'Daisy']);
    seatBotPlace($map, $exit);
    $account = seatBotAccount($map);
    Http::fake(['seat.test/devilsrage/api/discord/4242/assets*' => Http::response(['systems' => [
        ['system_id' => $neighbour, 'items' => 3, 'value' => 2.5e9, 'ships' => ['Orca']],
        ['system_id' => 31999999, 'items' => 1, 'value' => 10.0, 'ships' => []],
    ]])]);

    $reply = app(ListAssetsNearChainAction::class)->handle($account, $map->id, 2, false);

    expect($reply)->toContain('1 jump from **Test System '.$exit.'**')
        ->toContain('≈2.5b ISK · Orca')
        ->not->toContain('31999999');
});

it('finds the nearest exit for each asset system', function () {
    [$exit, $neighbour] = seatBotGatePair();
    $hits = app(AssetProximity::class)->near([$exit], [
        ['system_id' => $exit, 'items' => 1, 'value' => 5.0, 'ships' => []],
        ['system_id' => $neighbour, 'items' => 1, 'value' => 9.0, 'ships' => ['Rorqual']],
    ], 1);

    expect(array_column($hits, 'jumps', 'system_id'))->toBe([$exit => 0, $neighbour => 1]);
});

it('DMs an asset watcher once per new exit near their stuff', function () {
    [$exit, $neighbour] = seatBotGatePair();
    makeSolarsystem($exit, -0.1, 'eve');
    makeSolarsystem($neighbour, -0.1, 'eve');
    $map = Map::factory()->create();
    $account = seatBotAccount($map);
    Http::fake([
        'seat.test/devilsrage/api/discord/4242/assets*' => Http::response(['systems' => [
            ['system_id' => $neighbour, 'items' => 2, 'value' => 1e8, 'ships' => ['Orca']],
        ]]),
        'discord.com/api/v10/users/@me/channels' => Http::response(['id' => 'dm-channel']),
        'discord.com/api/v10/channels/dm-channel/messages' => Http::response(['id' => 'message']),
    ]);

    expect(app(ManageAssetWatchAction::class)->watch($account, $map->id, 3, false))->toStartWith('Watching **');

    $placement = seatBotPlace($map, $exit);
    app()->call([new EvaluateAssetWatchesJob($placement->id), 'handle']);
    app()->call([new EvaluateAssetWatchesJob($placement->id), 'handle']);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/channels/dm-channel/messages')
        && str_contains((string) $request['content'], 'is near your stuff'));
});

it('skips asset watches for wormhole systems', function () {
    $map = Map::factory()->create();
    $account = seatBotAccount($map);
    DiscordAssetWatch::factory()->create(['user_id' => $account->user_id, 'map_id' => $map->id]);
    Http::fake();

    app()->call([new EvaluateAssetWatchesJob(seatBotPlace($map, makeSolarsystem(31009371, -1.0, 'wh'))->id), 'handle']);

    Http::assertNothingSent();
});

it('stops asset watches on one map or all', function () {
    $map = Map::factory()->create();
    $other = Map::factory()->create();
    $account = seatBotAccount($map);
    DiscordAssetWatch::factory()->create(['user_id' => $account->user_id, 'map_id' => $map->id]);
    DiscordAssetWatch::factory()->create(['user_id' => $account->user_id, 'map_id' => $other->id]);

    expect(app(ManageAssetWatchAction::class)->stop($account, $map->id))->toBe('Stopped 1 asset watch.')
        ->and(app(ManageAssetWatchAction::class)->stop($account, null))->toBe('Stopped 1 asset watch.')
        ->and(app(ManageAssetWatchAction::class)->stop($account, null))->toBe('You had no asset watch there.');
});

it('shows a corp fit ready to import', function () {
    Http::fake([
        'seat.test/devilsrage/api/fits?*' => Http::response(['fits' => [
            ['id' => 7, 'name' => 'Muninn Shield', 'ship' => 'Muninn'],
            ['id' => 8, 'name' => 'Muninn Shield Logi Wing', 'ship' => 'Scimitar'],
        ]]),
        'seat.test/devilsrage/api/fits/7' => Http::response(['id' => 7, 'name' => 'Muninn Shield', 'ship' => 'Muninn', 'eft' => "[Muninn, Muninn Shield]\nGyrostabilizer II"]),
    ]);

    expect(app(ShowSeatFitAction::class)->handle('muninn shield'))->toContain("```\n[Muninn, Muninn Shield]\nGyrostabilizer II\n```")
        ->and(app(ShowSeatFitAction::class)->handle('Muninn'))->toStartWith('Several fits match');
});

it('says when no corp fit matches', function () {
    Http::fake(['seat.test/devilsrage/api/fits?*' => Http::response(['fits' => []])]);

    expect(app(ShowSeatFitAction::class)->handle('Nyx'))->toStartWith('No corp fit matches "Nyx"');
});

it('shows which characters can fly a corp fit', function () {
    Http::fake([
        'seat.test/devilsrage/api/fits?*' => Http::response(['fits' => [['id' => 7, 'name' => 'Muninn Shield', 'ship' => 'Muninn']]]),
        'seat.test/devilsrage/api/discord/4242/can-fly/7' => Http::response([
            'fit' => ['id' => 7, 'name' => 'Muninn Shield', 'ship' => 'Muninn'],
            'characters' => [
                ['character' => 'Kaelen Voss', 'can_fly' => true, 'close' => false, 'train_time' => '0m', 'seconds' => 0, 'missing' => [], 'missing_count' => 0],
                ['character' => 'Bram Hollow', 'can_fly' => false, 'close' => true, 'train_time' => '1d 2h', 'seconds' => 93600, 'missing' => ['Heavy Assault Cruisers 4'], 'missing_count' => 1],
            ],
        ]),
    ]);

    $reply = app(CheckCanFlyAction::class)->handle('4242', 'Muninn Shield');

    expect($reply)->toContain('✅ **Kaelen Voss** can fly it')
        ->toContain('🟡 **Bram Hollow** · 1 skill, ≈1d 2h · Heavy Assault Cruisers 4');
});
