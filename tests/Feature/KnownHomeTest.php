<?php

declare(strict_types=1);

use App\Events\Maps\KnownHomeConnectedEvent;
use App\Jobs\MapAlerts\AnnounceKnownHomeJob;
use App\Models\Map;
use App\Models\MapSolarsystem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * Patch 43: "Home of …" on a system; it pings when the system is connected.
 */
beforeEach(function () {
    $this->map = Map::factory()->create();
    $this->user = User::factory()->ownsMap($this->map)->create();
    $this->user->update(['preferred_character_id' => $this->user->characters->first()->id]);
    actingAs($this->user);
});

it('saves whose home a system is', function () {
    $system = MapSolarsystem::factory()->for($this->map)->create();

    $this->put(route('map-solarsystems.update', $system), ['home_of' => 'Hard Knocks', 'occupier_alias' => 'HK'])->assertRedirect();

    expect($system->fresh()->details->home_of)->toBe('Hard Knocks')
        ->and($system->fresh()->details->occupier_alias)->toBe('HK');
});

it('pings once when a marked home is connected', function () {
    Event::fake([KnownHomeConnectedEvent::class]);
    Cache::flush();
    $system = MapSolarsystem::factory()->for($this->map)->create();
    $system->details->update(['home_of' => 'Hard Knocks']);

    (new AnnounceKnownHomeJob($system->id))->handle(app(App\Services\Discord\ChainWayDescriber::class), app(App\Services\Discord\DiscordHttp::class));
    (new AnnounceKnownHomeJob($system->id))->handle(app(App\Services\Discord\ChainWayDescriber::class), app(App\Services\Discord\DiscordHttp::class));

    Event::assertDispatchedTimes(KnownHomeConnectedEvent::class, 1);
});

it('does not ping for a system nobody calls home', function () {
    Event::fake([KnownHomeConnectedEvent::class]);
    $system = MapSolarsystem::factory()->for($this->map)->create();

    (new AnnounceKnownHomeJob($system->id))->handle(app(App\Services\Discord\ChainWayDescriber::class), app(App\Services\Discord\DiscordHttp::class));

    Event::assertNotDispatched(KnownHomeConnectedEvent::class);
});
