<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A launch group. Main / Second / Third (key set, user_id null) are shared and locked
 * to one character per account; pilots' own groups (user_id set) are not locked.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $key
 * @property string $name
 * @property bool $locked
 * @property int $position
 */
class LaunchGroup extends Model
{
    public const PIVOT = 'devilsrage_account_character_launch_group';

    protected $table = 'devilsrage_launch_groups';

    protected $fillable = ['user_id', 'key', 'name', 'locked', 'position'];

    /** @var array<string, string> */
    protected $casts = ['locked' => 'boolean', 'user_id' => 'integer', 'position' => 'integer'];

    public function characters(): BelongsToMany
    {
        return $this->belongsToMany(AccountCharacter::class, self::PIVOT, 'launch_group_id', 'account_character_id');
    }

    /** The shared groups first, then the pilot's own by name. */
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('user_id')->orWhere('user_id', $userId))
            ->orderByRaw('user_id is not null')
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * @return Collection<int, LaunchGroup>
     */
    public static function forUser(int $userId): Collection
    {
        return static::query()->visibleTo($userId)->get();
    }

    /** CSS class suffix for the pill: main / second / third / custom. */
    public function pillClass(): string
    {
        return $this->key ?? 'custom';
    }
}
