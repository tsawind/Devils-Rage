<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * Patch 49: SeAT's My accounts asks which characters are logged in to the mapper, and its
 * "add to the mapper" button returns to SeAT afterwards.
 */
const SEAT_TEST_KEY = 'a-very-long-shared-key-for-the-seat-bot-1234567890';

beforeEach(function (): void {
    config(['services.seat_bot.key' => SEAT_TEST_KEY, 'services.seat_bot.url' => 'https://seat.example.test']);
});

function mapperToken(Character $character, ?string $refresh = 'refresh'): void
{
    EsiToken::query()->insert([
        'character_id' => $character->id,
        'character_owner_hash' => 'hash',
        'access_token' => 'access',
        'refresh_token' => $refresh,
        'expires_at' => now()->addMinutes(20),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('tells SeAT which characters have a working mapper login', function () {
    $in = Character::factory()->create();
    $lost = Character::factory()->create();
    mapperToken($in);
    mapperToken($lost, null);

    getJson(route('seat-api.characters', ['ids' => "{$in->id},{$lost->id},999,abc"]), ['X-DR-Key' => SEAT_TEST_KEY])
        ->assertSuccessful()
        ->assertExactJson([
            'characters' => [(string) $in->id => true, (string) $lost->id => false, '999' => false],
            'known' => [(string) $in->id => true, (string) $lost->id => true, '999' => false],
            'chat_log_off' => [],
        ]);
});

it('tells SeAT which characters have EVE\'s Log Chat to File off (Patch 54)', function () {
    $off = Character::factory()->create(['chat_log_to_file' => false]);
    $on = Character::factory()->create(['chat_log_to_file' => true]);
    $unknown = Character::factory()->create();

    getJson(route('seat-api.characters', ['ids' => "{$off->id},{$on->id},{$unknown->id}"]), ['X-DR-Key' => SEAT_TEST_KEY])
        ->assertSuccessful()
        ->assertJsonPath('chat_log_off', [(int) $off->id]);
});

it('answers SeAT only with the shared key', function () {
    getJson(route('seat-api.characters', ['ids' => '1']))->assertUnauthorized();
    getJson(route('seat-api.characters', ['ids' => '1']), ['X-DR-Key' => 'wrong'])->assertUnauthorized();

    config(['services.seat_bot.key' => '']);
    getJson(route('seat-api.characters', ['ids' => '1']), ['X-DR-Key' => ''])->assertNotFound();
});

it('adds a character and goes back to the SeAT page it came from', function () {
    actingAs(User::factory()->create());

    get(route('characters.add-from-seat', ['return' => 'https://seat.example.test/devilsrage/accounts#acct-3']))
        ->assertRedirect(route('eve.show', ['add_to_account' => 1]))
        ->assertSessionHas('redirect_to', 'https://seat.example.test/devilsrage/accounts#acct-3');
});

it('adds a public-only character without asking EVE for permissions', function () {
    actingAs(User::factory()->create());

    get(route('characters.add-from-seat', ['public' => 1]))
        ->assertRedirect(route('eve.show', ['add_to_account' => 1, 'without_scopes' => 1]));
});

it('never sends you back anywhere but our SeAT', function (string $return) {
    actingAs(User::factory()->create());

    get(route('characters.add-from-seat', ['return' => $return]))
        ->assertRedirect(route('eve.show', ['add_to_account' => 1]))
        ->assertSessionMissing('redirect_to');
})->with([
    'another site' => 'https://evil.example.test/devilsrage/accounts',
    'plain http' => 'http://seat.example.test/devilsrage/accounts',
    'look-alike host' => 'https://seat.example.test.evil.test/',
    'not an address' => 'javascript:alert(1)',
]);

it('asks you to log in to the mapper first', function () {
    get(route('characters.add-from-seat'))->assertRedirect(route('login'));
});
