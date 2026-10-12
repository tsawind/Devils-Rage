<?php

declare(strict_types=1);

use App\Actions\Discord\ListChainExitsAction;
use App\Actions\Discord\ManageChannelFeedAction;
use App\Actions\Discord\SeatMessages;
use App\Actions\Discord\ShowExtractionsAction;
use App\Actions\Discord\ShowHealthAction;
use App\Actions\Discord\ShowSeatAccountsAction;
use App\Actions\Discord\ShowStructureFuelAction;
use App\Enums\DiscordFeedKind;
use App\Enums\Permission;
use App\Jobs\MapAlerts\AnnounceChainExitJob;
use App\Models\Character;
use App\Models\DiscordAccount;
use App\Models\DiscordAssetWatch;
use App\Models\DiscordChannelFeed;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapAlert;
use App\Models\MapSolarsystem;
use App\Models\User;
use App\Services\Discord\ChainExitDescriber;
use App\Services\Discord\Commands\CommandCatalogue;
use App\Services\Discord\Commands\CommandRegistry;
use App\Services\Discord\Commands\HelpCommand;
use App\Services\Discord\DiscordInteractionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    config([
        'services.seat_bot.url' => 'https://seat.test',
        'services.seat_bot.key' => str_repeat('k', 40),
        'services.discord.bot_token' => 'bot-token',
    ]);
});

function overviewAccount(Map $map, string $discordUserId = '5151'): DiscordAccount
{
    $user = User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => Permission::Member])->for($map)))
        ->create();

    return DiscordAccount::factory()->for($user)->create(['discord_user_id' => $discordUserId])->load('user.characters');
}

/**
 * @return array<string, mixed>
 */
function overviewSeatAccounts(): array
{
    return [
        'accounts' => [[
            'nickname' => 'Farm One',
            'characters' => ['Kaelen Voss', 'Ivy Sol'],
            'omega' => ['state' => 'soon', 'until' => '2026-10-15', 'days_left' => 3],
            'mct' => ['slots' => 1, 'state' => 'auto', 'until' => null, 'days_left' => null],
            'alpha' => ['Ivy Sol'],
            'plex' => ['hangar' => 500, 'vault' => 1300, 'vault_at' => '2026-10-01', 'total' => 1800],
            'isk' => 2.5e9,
        ], [
            'nickname' => 'Main',
            'characters' => ['Main Pilot'],
            'omega' => ['state' => 'auto', 'until' => null, 'days_left' => null],
            'mct' => ['slots' => 0, 'state' => 'none', 'until' => null, 'days_left' => null],
            'alpha' => [],
            'plex' => ['hangar' => 0, 'vault' => null, 'vault_at' => null, 'total' => 0],
            'isk' => 1e8,
        ]],
        'loose' => [['character' => 'Scout Alt', 'plex' => 20, 'isk' => 0.0]],
        'omega_plex_month' => 300.0,
        'mct_plex_month' => 485.0,
    ];
}

it('builds /help from the registered commands', function () {
    $help = app(HelpCommand::class)->text(null);
    $names = collect(app(CommandRegistry::class)->all())
        ->map(fn ($command): string => $command->definition()->name())
        ->reject(fn (string $name): bool => $name === 'help');

    foreach ($names as $name) {
        expect($help)->toContain('`/'.$name.'`');
    }

    expect(mb_strlen($help))->toBeLessThan(1900);
});

it('explains one command with every subcommand and option', function () {
    $exits = app(HelpCommand::class)->text('exits');

    expect($exits)->toContain('**/exits**')
        ->toContain('`/exits now`')
        ->toContain('`/exits here`')
        ->toContain('`/exits stop`')
        ->toContain('`map` (optional)');
});

it('keeps long command help inside a Discord message', function () {
    expect(mb_strlen(app(HelpCommand::class)->text('alert')))->toBeLessThan(1900);
});

it('offers every other command as a /help choice', function () {
    $choices = app(HelpCommand::class)->definition()->toArray()['options'][0]['choices'];

    expect(array_column($choices, 'value'))->toContain('exits', 'plex', 'omega', 'extract', 'fuel')
        ->not->toContain('help');
});

it('expands subcommand groups in the catalogue', function () {
    $alert = collect(app(CommandCatalogue::class)->all())->firstWhere('name', 'alert');

    expect(array_column($alert['usages'], 'usage'))->toContain('/alert dm killmail', '/alert channel proximity')
        ->and($alert['section'])->toBe('Map')
        ->and(collect(app(CommandCatalogue::class)->all())->firstWhere('name', 'fuel')['section'])->toBe('SeAT');
});

it('shows PLEX and ISK per account', function () {
    Http::fake(['seat.test/devilsrage/api/discord/5151/accounts' => Http::response(overviewSeatAccounts())]);

    $reply = app(ShowSeatAccountsAction::class)->plex('5151');

    expect($reply)->toContain('**Farm One** · 1,800 PLEX (500 in hangars, 1,300 in vault (2026-10-01)) · 2.5b ISK · ≈6.0 months of Omega')
        ->toContain('**Main** · 0 PLEX (0 in hangars, vault not entered)')
        ->toContain('Scout Alt (no account) · 20 PLEX')
        ->toContain('**Total** 1,820 PLEX · 2.6b ISK');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-DR-Key', str_repeat('k', 40)));
});

it('shows Omega and MCT per account', function () {
    Http::fake(['seat.test/devilsrage/api/discord/5151/accounts' => Http::response(overviewSeatAccounts())]);

    $reply = app(ShowSeatAccountsAction::class)->omega('5151');

    expect($reply)->toContain('**Farm One** · ⚠️ Omega ends 2026-10-15 (3 days) · ✅ MCT×1 renews by itself · ⚠️ training at Alpha speed: Ivy Sol')
        ->toContain('**Main** · ✅ Omega renews by itself');
});

it('tells pilots to link SeAT for /plex, /omega and /extract', function () {
    Http::fake(['seat.test/*' => Http::response(['error' => 'not_linked'], 404)]);

    expect(app(ShowSeatAccountsAction::class)->plex('5151'))->toBe(SeatMessages::NOT_LINKED)
        ->and(app(ShowSeatAccountsAction::class)->omega('5151'))->toBe(SeatMessages::NOT_LINKED)
        ->and(app(ShowExtractionsAction::class)->handle('5151'))->toBe(SeatMessages::NOT_LINKED);
});

it('lists what could be extracted for quick ISK', function () {
    Http::fake(['seat.test/devilsrage/api/discord/5151/extract' => Http::response([
        'per_extraction' => 4e8,
        'characters' => [[
            'character' => 'Kaelen Voss',
            'count' => 3,
            'isk' => 1.2e9,
            'retrain_days' => 17.4,
            'skills' => [['name' => 'Mining', 'level' => 5, 'sp' => 256000], ['name' => 'Salvaging', 'level' => 4, 'sp' => 90510]],
        ]],
    ])]);

    expect(app(ShowExtractionsAction::class)->handle('5151'))
        ->toContain('3 extractions ≈ 1.2b ISK')
        ->toContain('**Kaelen Voss** · 3× ≈ 1.2b · retrain 17 days · from Mining 5, Salvaging 4');
});

it('lists structure fuel, soonest first', function () {
    $system = makeSolarsystem(30009601, 0.4, 'eve');
    Http::fake(['seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['structures' => [
        ['name' => 'Daisy Astra', 'type' => 'Astrahus', 'system_id' => $system, 'state' => 'shield_vulnerable', 'fuel_expires' => now()->addDay()->toIso8601String(), 'hours_left' => 30],
        ['name' => 'Old Raitaru', 'type' => 'Raitaru', 'system_id' => $system, 'state' => 'low_power', 'fuel_expires' => null, 'hours_left' => null],
    ]])]);

    expect(app(ShowStructureFuelAction::class)->handle('5151'))
        ->toContain('2 structures, 1 under a week')
        ->toContain('🔴 **Daisy Astra** (Astrahus, Test System '.$system.') · 30h left')
        ->toContain('⚫ **Old Raitaru** (Raitaru, Test System '.$system.') · no fuel (low power)');
});

it('keeps structure fuel to corp leaders', function () {
    Http::fake(['seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['error' => 'not_leader'], 403)]);

    expect(app(ShowStructureFuelAction::class)->handle('5151'))->toBe('Only corp leaders (CEO, directors) can see that in SeAT.');
});

it('lists the chain exits with the nearest trade hub', function () {
    $map = Map::factory()->create(['name' => 'Daisy']);
    makeSolarsystem(30000144, 0.9, 'eve');
    MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => 30000144]);
    MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31009602, -1.0, 'wh')]);
    $account = overviewAccount($map);

    $reply = app(ListChainExitsAction::class)->handle($account, $map->id);

    expect($reply)->toContain('🚪 **Exits on Daisy** (1)')
        ->toContain('**Test System 30000144** (0.9 high-sec · Test Region) · Jita 1 jump')
        ->not->toContain('31009602');
});

it('names the trade hub itself', function () {
    expect(app(ChainExitDescriber::class)->nearestHub(30000142))->toBe('is Jita');
});

it('needs a server channel and Manage Channels to start a feed', function () {
    $map = Map::factory()->create();
    $account = overviewAccount($map);
    $feeds = app(ManageChannelFeedAction::class);

    expect($feeds->start($account, DiscordFeedKind::ChainExits, $map->id, null, null, true))->toBe(ManageChannelFeedAction::NEEDS_CHANNEL)
        ->and($feeds->start($account, DiscordFeedKind::ChainExits, $map->id, '1', '2', false))->toBe(ManageChannelFeedAction::NEEDS_MANAGE_CHANNELS)
        ->and($feeds->start($account, DiscordFeedKind::ChainExits, Map::factory()->create()->id, '1', '2', true))->toBe('That map is unavailable.')
        ->and(DiscordChannelFeed::query()->count())->toBe(0);
});

it('starts and stops chain exit posts in a channel', function () {
    $map = Map::factory()->create(['name' => 'Daisy']);
    $account = overviewAccount($map);
    $feeds = app(ManageChannelFeedAction::class);

    expect($feeds->start($account, DiscordFeedKind::ChainExits, $map->id, '111', '222', true))->toContain('**Daisy** gets posted here');
    $feeds->start($account, DiscordFeedKind::ChainExits, $map->id, '111', '222', true);

    expect(DiscordChannelFeed::query()->count())->toBe(1)
        ->and($feeds->stop(DiscordFeedKind::ChainExits, null, '222', true))->toBe('Stopped "New chain exits" in this channel.')
        ->and(DiscordChannelFeed::query()->count())->toBe(0);
});

it('only lets corp leaders start the daily fuel post', function () {
    $account = overviewAccount(Map::factory()->create());
    Http::fake(['seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['error' => 'not_leader'], 403)]);

    expect(app(ManageChannelFeedAction::class)->start($account, DiscordFeedKind::FuelDaily, null, '111', '222', true))
        ->toBe('Only corp leaders (CEO, directors) can see that in SeAT.')
        ->and(DiscordChannelFeed::query()->count())->toBe(0);
});

it('posts a new k-space exit once into each following channel', function () {
    $map = Map::factory()->create(['name' => 'Daisy']);
    $account = overviewAccount($map);
    DiscordChannelFeed::factory()->create(['map_id' => $map->id, 'created_by_user_id' => $account->user_id, 'discord_channel_id' => 'exits-channel']);
    makeSolarsystem(30000144, 0.9, 'eve');
    Http::fake(['discord.com/api/v10/channels/exits-channel/messages' => Http::response(['id' => 'message'])]);

    $placement = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => 30000144]);
    app()->call([new AnnounceChainExitJob($placement->id), 'handle']);
    app()->call([new AnnounceChainExitJob($placement->id), 'handle']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['content'], 'New exit on **Daisy**: **Test System 30000144**'));
    expect(DiscordChannelFeed::query()->first()->last_posted_at)->not->toBeNull();
});

it('does not post wormhole systems as exits', function () {
    $map = Map::factory()->create();
    DiscordChannelFeed::factory()->create(['map_id' => $map->id]);
    Http::fake();

    $placement = MapSolarsystem::factory()->for($map)->create(['solarsystem_id' => makeSolarsystem(31009603, -1.0, 'wh')]);
    app()->call([new AnnounceChainExitJob($placement->id), 'handle']);

    Http::assertNothingSent();
});

it('posts the daily fuel summary into each fuel channel', function () {
    $account = overviewAccount(Map::factory()->create());
    DiscordChannelFeed::factory()->fuel()->create(['created_by_user_id' => $account->user_id, 'discord_channel_id' => 'fuel-channel']);
    Http::fake([
        'seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['structures' => []]),
        'discord.com/api/v10/channels/fuel-channel/messages' => Http::response(['id' => 'message']),
    ]);

    $this->artisan('discord:post-fuel')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/channels/fuel-channel/messages')
        && str_contains((string) $request['content'], 'SeAT has no corp structures yet'));
});

it('shows feeds, asset watches, commands and alert rules on the Discord settings page', function () {
    $map = Map::factory()->create();
    $manager = User::factory()->ownsMap($map)->create();
    DiscordChannelFeed::factory()->create(['map_id' => $map->id]);
    DiscordChannelFeed::factory()->fuel()->create();
    DiscordChannelFeed::factory()->create();
    DiscordAssetWatch::factory()->create(['map_id' => $map->id, 'max_jumps' => 4]);
    MapAlert::factory()->discordDm()->create([
        'map_id' => $map->id,
        'type' => 'killmail',
        'target_solarsystem_id' => null,
        'max_jumps' => 0,
        'exclude_highsec' => true,
    ]);

    actingAs($manager)
        ->get(route('maps.settings.discord.show', $map))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('feeds', 2)
            ->has('assetWatches', 1)
            ->where('assetWatches.0.max_jumps', 4)
            ->where('commands.0.name', 'help')
            ->where('botAlerts.0.rules_summary', 'kills inside the chain, no high-sec')
            ->etc());
});

it('lets map managers stop a chain exit feed but not the corp fuel post', function () {
    $map = Map::factory()->create();
    $manager = User::factory()->ownsMap($map)->create();
    $exits = DiscordChannelFeed::factory()->create(['map_id' => $map->id]);
    $fuel = DiscordChannelFeed::factory()->fuel()->create();
    $other = DiscordChannelFeed::factory()->create();

    actingAs($manager)->delete(route('discord-channel-feeds.destroy', $exits))->assertRedirect();
    actingAs($manager)->delete(route('discord-channel-feeds.destroy', $fuel))->assertNotFound();
    actingAs($manager)->delete(route('discord-channel-feeds.destroy', $other))->assertForbidden();

    expect(DiscordChannelFeed::query()->pluck('id')->all())->toEqualCanonicalizing([$fuel->id, $other->id]);
});

it('answers autocomplete for every command with an autocompleted option', function () {
    expect(app(DiscordInteractionHandler::class)->autocompletedCommands())
        ->toContain('alert', 'alerts', 'route', 'caps', 'assets', 'exits')
        ->not->toContain('help', 'fuel', 'plex');
});

/**
 * @return array<string, mixed>
 */
function overviewHealth(array $overrides = []): array
{
    return array_merge([
        'leaders' => [
            ['name' => 'Darkness Holder', 'role' => 'CEO', 'status' => 'ok'],
            ['name' => '02Vicarious', 'role' => 'Director', 'status' => 'ok'],
        ],
        'expired' => [],
        'expired_count' => 0,
        'failed_jobs' => 3,
    ], $overrides);
}

it('reports SeAT health as all good', function () {
    Cache::forever(ShowHealthAction::BACKUP_KEY, now()->subHours(5)->toIso8601String());
    Http::fake(['seat.test/devilsrage/api/discord/5151/health' => Http::response(overviewHealth())]);

    $report = app(ShowHealthAction::class)->report('5151');

    expect($report['problems'])->toBe(0)
        ->and($report['text'])->toContain('SeAT health: all good')->toContain('2 leader logins working');
});

it('lists broken leader logins, expired characters and a missing backup', function () {
    Cache::forget(ShowHealthAction::BACKUP_KEY);
    Http::fake(['seat.test/devilsrage/api/discord/5151/health' => Http::response(overviewHealth([
        'leaders' => [['name' => '02Vicarious', 'role' => 'Director', 'status' => 'expired']],
        'expired' => ['Devil Coffin 2'],
        'expired_count' => 1,
        'failed_jobs' => 80,
    ]))]);

    $report = app(ShowHealthAction::class)->report('5151');

    expect($report['problems'])->toBe(4)
        ->and($report['text'])->toContain('**02Vicarious** (Director): SeAT login expired')
        ->toContain('1 character in accounts needs to log in to SeAT again: Devil Coffin 2')
        ->toContain('80 SeAT jobs failed')
        ->toContain('No nightly backup has reported in yet');
});

it('records the nightly backup', function () {
    Cache::forget(ShowHealthAction::BACKUP_KEY);

    $this->artisan('devilsrage:backup-done')->assertSuccessful();

    expect(Cache::get(ShowHealthAction::BACKUP_KEY))->toBeString();
});

it('posts health only when something is wrong, except with --always', function () {
    $this->travelTo(now()->next('Monday'));
    Cache::forever(ShowHealthAction::BACKUP_KEY, now()->toIso8601String());
    $account = overviewAccount(Map::factory()->create());
    DiscordChannelFeed::factory()->create(['kind' => DiscordFeedKind::Health, 'map_id' => null, 'created_by_user_id' => $account->user_id, 'discord_channel_id' => 'health-channel']);
    Http::fake([
        'seat.test/devilsrage/api/discord/5151/health' => Http::response(overviewHealth()),
        'discord.com/api/v10/channels/health-channel/messages' => Http::response(['id' => 'message']),
    ]);

    $this->artisan('discord:post-health')->assertSuccessful();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/channels/'));

    $this->artisan('discord:post-health', ['--always' => true])->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/channels/health-channel/messages'));
});

it('posts the weekly fuel summary only into weekly channels', function () {
    $account = overviewAccount(Map::factory()->create());
    DiscordChannelFeed::factory()->fuel()->create(['created_by_user_id' => $account->user_id, 'discord_channel_id' => 'daily-channel']);
    DiscordChannelFeed::factory()->create(['kind' => DiscordFeedKind::FuelWeekly, 'map_id' => null, 'created_by_user_id' => $account->user_id, 'discord_channel_id' => 'weekly-channel']);
    Http::fake([
        'seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['structures' => []]),
        'discord.com/api/v10/channels/*' => Http::response(['id' => 'message']),
    ]);

    $this->artisan('discord:post-fuel', ['--weekly' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/channels/weekly-channel/messages'));
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/channels/daily-channel/messages'));
});

it('stops both fuel summaries with one /fuel stop', function () {
    DiscordChannelFeed::factory()->fuel()->create(['discord_channel_id' => '333']);
    DiscordChannelFeed::factory()->create(['kind' => DiscordFeedKind::FuelWeekly, 'map_id' => null, 'discord_channel_id' => '333']);

    $reply = app(ManageChannelFeedAction::class)->stop([DiscordFeedKind::FuelDaily, DiscordFeedKind::FuelWeekly], null, '333', true);

    expect($reply)->toBe('Stopped "Daily structure fuel", "Weekly structure fuel" in this channel.')
        ->and(DiscordChannelFeed::query()->count())->toBe(0);
});

it('lists control towers alike on one line with strontium', function () {
    $system = makeSolarsystem(30009611, -1.0, 'wh');
    $tower = fn (string $moon): array => ['kind' => 'pos', 'name' => 'Test System '.$system.' VII - Moon '.$moon, 'type' => 'Caldari Control Tower Small', 'system_id' => $system, 'state' => 'online', 'fuel_expires' => null, 'hours_left' => 234, 'strontium_hours' => 41];
    Http::fake(['seat.test/devilsrage/api/discord/5151/fuel' => Http::response(['structures' => [
        ['kind' => 'structure', 'name' => 'Old-Daisy', 'type' => 'Fortizar', 'system_id' => $system, 'state' => 'shield_vulnerable', 'fuel_expires' => null, 'hours_left' => 1245, 'strontium_hours' => null],
        $tower('4'),
        $tower('3'),
    ]])]);

    expect(app(ShowStructureFuelAction::class)->handle('5151'))
        ->toContain('1 structure, 2 POS, 0 under a week')
        ->toContain('🟢 **2× Caldari Control Tower Small** (Test System '.$system.': VII-4, VII-3) · 9d 18h left · stront 41h')
        ->toContain('**Old-Daisy** (Fortizar, Test System '.$system.') · 51d 21h left');
});
