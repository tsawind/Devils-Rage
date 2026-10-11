<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Http\Resources\MapUserSettingResource;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/**
 * Patch 37: a map has a default background set by its managers; each pilot picks the
 * map's default, none, or their own picture.
 */
function backgroundUser(Map $map, Permission $permission): User
{
    return User::factory()
        ->has(Character::factory()->has(MapAccess::factory(['permission' => $permission])->for($map)))
        ->create();
}

/**
 * @return array<string, mixed>
 */
function shownBackground(User $user, Map $map): array
{
    $settings = $user->mapUserSettings()->firstOrCreate(['map_id' => $map->id]);

    return (new MapUserSettingResource($settings))->toArray(Request::create('/'));
}

it('patch 37: a manager sets the map default and every pilot sees it, fit to view', function () {
    Storage::fake('public');
    $map = Map::factory()->create();
    $manager = backgroundUser($map, Permission::Manager);
    $member = backgroundUser($map, Permission::Member);

    actingAs($manager)->post(route('maps.default-background.store', $map), [
        'background_image' => UploadedFile::fake()->image('devil.png', 1920, 1080),
    ])->assertRedirect();

    $map->refresh();
    Storage::disk('public')->assertExists($map->background_image_path);
    $shown = shownBackground($member, $map);
    expect($shown['background_choice'])->toBe('map')
        ->and($shown['background_image_url'])->toBe(Storage::disk('public')->url($map->background_image_path))
        ->and($shown['background_image_mode'])->toBe('viewport');
});

it('patch 37: a manager can use their own picture as the map default', function () {
    Storage::fake('public');
    $map = Map::factory()->create();
    $manager = backgroundUser($map, Permission::Manager);
    actingAs($manager)->post(route('maps.background-image.store', $map), [
        'background_image' => UploadedFile::fake()->image('mine.png', 800, 600),
    ]);

    actingAs($manager)->post(route('maps.default-background.store', $map), ['from_own' => '1'])->assertRedirect();

    expect($map->refresh()->background_image_path)->toStartWith("map-backgrounds/{$map->id}/default/");
    Storage::disk('public')->assertExists($map->background_image_path);

    // The same picture again keeps the file (it lands on the same path).
    actingAs($manager)->post(route('maps.default-background.store', $map), ['from_own' => '1'])->assertRedirect();
    Storage::disk('public')->assertExists($map->refresh()->background_image_path);
});

it('patch 37: a member cannot set the map default', function () {
    Storage::fake('public');
    $map = Map::factory()->create();

    actingAs(backgroundUser($map, Permission::Member))->post(route('maps.default-background.store', $map), [
        'background_image' => UploadedFile::fake()->image('devil.png'),
    ])->assertForbidden();
    actingAs(backgroundUser($map, Permission::Member))->delete(route('maps.default-background.destroy', $map))->assertForbidden();
});

it('patch 37: a pilot picks none, their own picture, or the map default', function () {
    Storage::fake('public');
    $map = Map::factory()->create(['background_image_path' => 'map-backgrounds/1/default/devil.png', 'background_image_mode' => 'viewport']);
    $member = backgroundUser($map, Permission::Member);

    actingAs($member)->put(route('maps.user-settings.update', $map), ['background_choice' => 'none'])->assertRedirect();
    expect(shownBackground($member, $map)['background_image_url'])->toBeNull();

    actingAs($member)->post(route('maps.background-image.store', $map), ['background_image' => UploadedFile::fake()->image('mine.png')]);
    $shown = shownBackground($member, $map);
    expect($shown['background_choice'])->toBe('own')
        ->and($shown['background_image_url'])->toBe($shown['own_background_image_url']);

    actingAs($member)->put(route('maps.user-settings.update', $map), ['background_choice' => 'map'])->assertRedirect();
    expect(shownBackground($member, $map)['background_image_url'])->toBe(Storage::disk('public')->url('map-backgrounds/1/default/devil.png'));
});
