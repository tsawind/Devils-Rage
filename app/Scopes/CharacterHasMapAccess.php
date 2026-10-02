<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Enums\Permission;
use App\Models\Character;
use App\Models\Map;
use App\Models\MapAccess;
use Illuminate\Database\Eloquent\Builder;

final readonly class CharacterHasMapAccess
{
    public function __construct(private Map $map, private bool $without_guests = false) {}

    /**
     * @param  Builder<Character>  $query
     * @return Builder<Character>
     */
    public function __invoke(Builder $query): Builder
    {
        // Devil's Rage: access is per account. An alt gets the best access of any
        // character on the same account (the main picked when joining), the same
        // way Map::getUserPermission already decides what the account may do.
        return $query
            ->whereExists(MapAccess::query()
                ->notExpired()
                ->whereBelongsTo($this->map)
                ->whereExists(fn ($accountCharacters) => $accountCharacters
                    ->selectRaw('1')
                    ->from('characters as account_characters')
                    ->where(fn ($same) => $same
                        ->whereColumn('account_characters.id', 'characters.id')
                        ->orWhere(fn ($sameUser) => $sameUser
                            ->whereNotNull('characters.user_id')
                            ->whereColumn('account_characters.user_id', 'characters.user_id')
                        )
                    )
                    ->where(fn ($match) => $match
                        ->whereColumn('map_access.accessible_id', 'account_characters.id')
                        ->orWhereColumn('map_access.accessible_id', 'account_characters.corporation_id')
                        ->orWhereColumn('map_access.accessible_id', 'account_characters.alliance_id')
                    )
                )
                ->when($this->without_guests, fn (Builder $query) => $query
                    ->where('permission', '!=', Permission::Viewer)
                )
            );
    }
}
