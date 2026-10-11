<?php

declare(strict_types=1);

use App\Models\Map;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('Map.{map}', function (User $user, Map $map) {
    return $user->can('view', $map);
});

// Patch 37: who has the map open right now (managers see the list in the pilots area).
Broadcast::channel('MapPresence.{map}', function (User $user, Map $map): array|false {
    if (! $user->can('view', $map)) {
        return false;
    }
    $character = $user->active_character;

    return [
        'id' => $user->id,
        'name' => $character?->name ?? $user->name,
        'main' => $character?->seat_main_name,
        'permission' => $map->getUserPermission($user)?->value,
    ];
});

Broadcast::channel('User.{userId}', function (User $user, string $userId): bool {
    return (int) $userId === $user->id;
});
