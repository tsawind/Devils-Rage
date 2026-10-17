<?php

declare(strict_types=1);

use App\Models\Map;
use App\Models\User;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Broadcast;

// Patch 39: share-link viewers (not logged in) follow the map live too: the map-share guard
// stands for them, and the map's own share-link check decides (it reads the session).
Broadcast::channel('Map.{map}', function (User|GenericUser $user, Map $map): bool {
    return $user instanceof User ? $user->can('view', $map) : Gate::forUser(null)->allows('view', $map);
}, ['guards' => ['web', 'map-share']]);

Broadcast::channel('User.{userId}', function (User $user, string $userId): bool {
    return (int) $userId === $user->id;
});
