<?php

declare(strict_types=1);

use App\Enums\MapWebhookMentionType;
use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\MapRallyPing;
use App\Models\MapWebhook;
use App\Models\MapWebhookRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

function rallyPinger(Map $map, Permission $permission): User
{
    return User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
}

function rallyMap(): Map
{
    $map = Map::factory()->create();
    makeSolarsystem(31000005);
    $map->update(['rally_solarsystem_id' => 31000005]);

    return $map;
}

beforeEach(function () {
    Cache::flush();
    Http::fake(['discord.com/*' => Http::response(null, 204)]);
});

it('patch 29: a member pings the rally point with a saved role mention, sections and note', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    $role = MapWebhookRole::factory()->for($map)->create(['mention_type' => MapWebhookMentionType::Role, 'discord_role_id' => '42']);
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), [
        'map_webhook_id' => $webhook->id,
        'mention' => 'role:'.$role->id,
        'sections' => [
            ['title' => 'Safest route', 'text' => 'Daisy → Bravo QAL → Delta'],
            ['title' => 'Will it hold? (experimental)', 'text' => 'H296: 2.1b left'],
        ],
        'note' => 'Armor doctrine',
    ])->assertRedirect()->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request): bool {
        $embed = $request['embeds'][0];

        return $request['content'] === '<@&42>'
            && $request['allowed_mentions'] === ['roles' => ['42']]
            && str_starts_with($embed['title'], '⚑ Form up at')
            && $embed['fields'][0]['name'] === 'Safest route'
            && $embed['fields'][1]['name'] === 'Will it hold? (experimental)'
            && $embed['fields'][2]['value'] === 'Armor doctrine';
    });
});

it('patch 29: @here, @everyone and no mention', function (string $mention, ?string $content) {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Manager));

    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $webhook->id, 'mention' => $mention])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => ($request['content'] ?? null) === $content);
})->with([
    'here' => ['here', '@here'],
    'everyone' => ['everyone', '@everyone'],
    'none' => ['none', null],
]);

it('patch 29: viewers cannot ping', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Viewer));

    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $webhook->id, 'mention' => 'none'])->assertForbidden();

    Http::assertNothingSent();
});

it('patch 29: a second ping within two minutes is refused', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $webhook->id, 'mention' => 'none'])->assertSessionHasNoErrors();
    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $webhook->id, 'mention' => 'none'])->assertSessionHasErrors('map_webhook_id');

    Http::assertSentCount(1);
});

it('patch 29: needs a rally point and a webhook from the same map', function () {
    $map = Map::factory()->create();
    $other = MapWebhook::factory()->create();
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $other->id, 'mention' => 'none'])->assertSessionHasErrors('map_webhook_id');

    $rally = rallyMap();
    actingAs(rallyPinger($rally, Permission::Member));
    $this->post(route('maps.rally-ping.store', $rally), ['map_webhook_id' => $other->id, 'mention' => 'none'])->assertNotFound();

    Http::assertNothingSent();
});

it('patch 29c: sections past the embed limit are left out with a note', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Member));
    $long = str_repeat('x', 1000);

    $this->post(route('maps.rally-ping.store', $map), [
        'map_webhook_id' => $webhook->id,
        'mention' => 'everyone',
        'sections' => array_map(fn (int $index): array => ['title' => 'Section '.$index, 'text' => $long], range(1, 6)),
    ])->assertSessionHasNoErrors();

    Http::assertSent(function (Request $request): bool {
        $fields = $request['embeds'][0]['fields'];

        return count($fields) === 5 && end($fields)['name'] === 'More';
    });
});

it('patch 29c: refuses more than 8 sections', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), [
        'map_webhook_id' => $webhook->id,
        'mention' => 'none',
        'sections' => array_fill(0, 9, ['title' => 'A', 'text' => 'B']),
    ])->assertSessionHasErrors('sections');
});

it('patch 30: rally moved and stand down post their own titles and are logged', function (string $kind, string $title) {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create(['name' => 'devils-pings']);
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), ['kind' => $kind, 'map_webhook_id' => $webhook->id, 'mention' => 'none'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request['embeds'][0]['title'], $title));
    $log = MapRallyPing::query()->where('map_id', $map->id)->sole();
    expect($log->kind)->toBe($kind)
        ->and($log->channel)->toBe('devils-pings')
        ->and($log->mention)->toBeNull();
})->with([
    'moved' => ['moved', '⚑ Rally moved: form up at'],
    'stand down' => ['stand_down', '✋ Stand down'],
]);

it('patch 30: no @here or @everyone for rally moved or stand down', function (string $kind, string $mention) {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), ['kind' => $kind, 'map_webhook_id' => $webhook->id, 'mention' => $mention])
        ->assertSessionHasErrors('mention');

    Http::assertNothingSent();
})->with([
    ['moved', 'here'],
    ['moved', 'everyone'],
    ['stand_down', 'here'],
    ['stand_down', 'everyone'],
]);

it('patch 30: a stand down right after a form up is allowed (cooldown per kind)', function () {
    $map = rallyMap();
    $webhook = MapWebhook::factory()->for($map)->create();
    actingAs(rallyPinger($map, Permission::Member));

    $this->post(route('maps.rally-ping.store', $map), ['map_webhook_id' => $webhook->id, 'mention' => 'everyone'])->assertSessionHasNoErrors();
    $this->post(route('maps.rally-ping.store', $map), ['kind' => 'stand_down', 'map_webhook_id' => $webhook->id, 'mention' => 'none'])->assertSessionHasNoErrors();

    Http::assertSentCount(2);
    expect(MapRallyPing::query()->where('map_id', $map->id)->pluck('mention')->all())->toContain('@everyone');
});

it('patch 30: the Discord settings page shows the ping log', function () {
    $map = rallyMap();
    MapRallyPing::factory()->for($map)->create(['title' => '⚑ Form up at Daisy']);
    actingAs(rallyPinger($map, Permission::Manager));

    $this->get(route('maps.settings.discord.show', $map))
        ->assertInertia(fn ($page) => $page->has('rallyPings', 1)->where('rallyPings.0.title', '⚑ Form up at Daisy'));
});
