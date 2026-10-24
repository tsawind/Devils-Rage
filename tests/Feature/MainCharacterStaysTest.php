<?php

declare(strict_types=1);

use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use NicolasKion\Esi\DTO\CharacterAffiliation;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\Esi;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;
use function Pest\Laravel\withSession;

/**
 * The main a pilot picks stays their main: adding alts or logging in again with an alt
 * doesn't switch it.
 *
 * @return array{0: User, 1: Character, 2: Character}
 */
function pilotWithMainAndAlt(): array
{
    $user = User::factory()->create();
    $main = Character::factory()->create(['user_id' => $user->id]);
    $alt = Character::factory()->create(['user_id' => $user->id]);

    return [$user, $main, $alt];
}

function eveLoginReturns(Character $character): void
{
    Bus::fake();
    config()->set('access.allowed_affiliation_ids', []);
    $character->update(['character_owner_hash' => 'main-stays-hash']);

    $socialiteUser = new SocialiteUser();
    $socialiteUser->attributes = [
        'character_id' => $character->id,
        'character_name' => $character->name,
        'character_owner_hash' => 'main-stays-hash',
    ];
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->accessTokenResponseBody = ['token_type' => 'Bearer', 'expires_in' => 1200];
    $socialiteUser->user = ['scp' => []];
    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);

    $esi = Mockery::mock(Esi::class);
    $esi->shouldReceive('getAffiliations')->andReturn(new EsiResult(
        data: [new CharacterAffiliation((int) $character->id, (int) $character->corporation_id, $character->alliance_id === null ? null : (int) $character->alliance_id, null)],
    ));
    app()->instance(Esi::class, $esi);
}

it('saves the main you switch to for the next login', function (): void {
    [$user, , $alt] = pilotWithMainAndAlt();

    actingAs($user);
    put(route('user-characters.update', $alt))->assertRedirect();

    expect($user->fresh()->preferred_character_id)->toEqual($alt->id);
});

it('keeps your main when you add a character', function (): void {
    [$user, $main] = pilotWithMainAndAlt();
    $user->update(['preferred_character_id' => $main->id]);
    $new = Character::factory()->create(['user_id' => $user->id]);
    eveLoginReturns($new);

    actingAs($user);
    withSession(['add_to_account' => $user->id, 'active_character_id' => $main->id])
        ->get(route('eve.store'))
        ->assertRedirect();

    expect(session('active_character_id'))->toEqual($main->id);
});

it('starts a fresh login on your saved main, not the character you logged in with', function (): void {
    [$user, $main, $alt] = pilotWithMainAndAlt();
    $user->update(['preferred_character_id' => $main->id]);
    eveLoginReturns($alt);

    get(route('eve.store'))->assertRedirect();

    expect(session('active_character_id'))->toEqual($main->id);
});

it('uses the login character when no main was saved yet', function (): void {
    [, , $alt] = pilotWithMainAndAlt();
    eveLoginReturns($alt);

    get(route('eve.store'))->assertRedirect();

    expect(session('active_character_id'))->toEqual($alt->id);
});

it('only moves your main when you remove the main itself', function (): void {
    [$user, $main, $alt] = pilotWithMainAndAlt();
    Character::factory()->create(['user_id' => $user->id]);
    $user->update(['preferred_character_id' => $main->id]);

    actingAs($user);
    withSession(['active_character_id' => $main->id])
        ->delete(route('user-characters.delete', $alt))
        ->assertRedirect();

    expect(session('active_character_id'))->toEqual($main->id);
});
