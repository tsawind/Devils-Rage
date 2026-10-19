<?php

declare(strict_types=1);

use App\Events\Characters\WindowSwitchRequestedEvent;
use App\Models\Character;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * Patch 45d: switch a character's own window (any browser) to another of your characters.
 */
it('asks your other window to switch characters', function () {
    Event::fake([WindowSwitchRequestedEvent::class]);
    $user = User::factory()->create();
    $from = Character::factory()->create(['user_id' => $user->id]);
    $to = Character::factory()->create(['user_id' => $user->id, 'name' => 'Second Scanner']);
    actingAs($user);

    post(route('window-switch.store'), ['from_character_id' => $from->id, 'to_character_id' => $to->id])->assertSessionHasNoErrors()->assertRedirect();

    Event::assertDispatched(WindowSwitchRequestedEvent::class, fn (WindowSwitchRequestedEvent $event): bool => $event->user_id === $user->id
        && $event->from_character_id === $from->id
        && $event->to_character_id === $to->id
        && $event->to_name === 'Second Scanner');
});

it('does not switch to someone else\'s character', function () {
    Event::fake([WindowSwitchRequestedEvent::class]);
    $user = User::factory()->create();
    $from = Character::factory()->create(['user_id' => $user->id]);
    $theirs = Character::factory()->create();
    actingAs($user);

    post(route('window-switch.store'), ['from_character_id' => $from->id, 'to_character_id' => $theirs->id])->assertSessionHasErrors('to_character_id');

    Event::assertNotDispatched(WindowSwitchRequestedEvent::class);
});
