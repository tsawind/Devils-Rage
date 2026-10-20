<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use Illuminate\Support\Facades\Route;
use Seat\Eveapi\Models\RefreshToken;
use Seat\Web\Models\User;
use Warlof\Seat\Connector\Models\User as ConnectorUser;

/**
 * Patch 39: the getting-started checklist on Devil's Rage → Home, and what the one-time
 * reminder tells pilots they still miss.
 */
final class GettingStarted
{
    /**
     * @return list<array{key: string, label: string, hint: string, done: bool, optional: bool, link: string|null}>
     */
    public function checklist(User $user): array
    {
        $characterIds = array_map('intval', $user->associatedCharacterIds());
        $live = RefreshToken::query()->where('user_id', $user->id)->pluck('character_id')->map(fn ($id): int => (int) $id)->all();
        $expired = RefreshToken::onlyTrashed()->where('user_id', $user->id)->whereNotIn('character_id', $live)->count();
        $grouped = AccountCharacter::query()->whereIn('character_id', $characterIds)->count();
        $discord = class_exists(ConnectorUser::class) && ConnectorUser::query()
            ->where('connector_type', 'discord')
            ->where('user_id', $user->id)
            ->exists();
        $name = AccountSetting::query()->whereKey($user->id)->value('person_name');

        return [
            [
                'key' => 'discord',
                'label' => 'Link your Discord',
                'hint' => 'Gives you the corp roles and lets the bot answer you.',
                'done' => $discord,
                'optional' => false,
                'link' => Route::has('seat-connector.identities') ? route('seat-connector.identities') : url('seat-connector/identities'),
            ],
            [
                'key' => 'characters',
                'label' => 'Add all your characters',
                'hint' => 'My accounts: Log in a character in each empty slot, one EVE account at a time.',
                'done' => count($characterIds) > 1,
                'optional' => false,
                'link' => route('devilsrage.accounts.index'),
            ],
            [
                'key' => 'logins',
                'label' => 'Renew expired logins',
                'hint' => $expired > 0 ? "{$expired} character login(s) expired: My accounts shows which, with a button to log them in again." : 'Every character login works.',
                'done' => $expired === 0,
                'optional' => false,
                'link' => route('devilsrage.accounts.index'),
            ],
            [
                'key' => 'accounts',
                'label' => 'Group characters into accounts',
                'hint' => 'My accounts: which characters share an EVE account, and their launch group.',
                'done' => $characterIds !== [] && $grouped >= count($characterIds),
                'optional' => false,
                'link' => route('devilsrage.accounts.index'),
            ],
            [
                'key' => 'name',
                'label' => 'Set the name you go by',
                'hint' => 'Optional: shown on the mapper and Discord instead of your main\'s name.',
                'done' => is_string($name) && $name !== '',
                'optional' => true,
                'link' => route('devilsrage.accounts.index'),
            ],
        ];
    }

    /**
     * What a pilot still misses (the required items only).
     *
     * @return list<string>
     */
    public function missing(User $user): array
    {
        return array_values(array_map(
            fn (array $item): string => $item['label'],
            array_filter($this->checklist($user), fn (array $item): bool => ! $item['done'] && ! $item['optional']),
        ));
    }
}
