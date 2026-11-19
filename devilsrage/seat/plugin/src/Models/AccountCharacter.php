<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterInfo;

/**
 * @property int $id
 * @property int $account_id
 * @property int $character_id
 * @property int $position
 * @property bool $external
 * @property bool $public_only
 * @property string|null $external_name
 * @property string|null $external_corporation
 * @property string|null $external_alliance
 */
class AccountCharacter extends Model
{
    protected $table = 'devilsrage_account_characters';

    public const EXTERNAL_FITS = 'devilsrage_external_fits';

    protected $fillable = ['account_id', 'character_id', 'position', 'external', 'public_only', 'access_level', 'access_scopes', 'external_name', 'external_corporation', 'external_alliance'];

    /** @var array<string, string> */
    protected $casts = ['character_id' => 'integer', 'account_id' => 'integer', 'position' => 'integer', 'external' => 'boolean', 'public_only' => 'boolean', 'access_scopes' => 'array'];

    /**
     * Patch 74: how much it shares: devil (everything), ally, partial or public.
     */
    public function accessLevel(): string
    {
        if ($this->public_only) {
            return 'public';
        }

        // 19 Nov: Partial folded into Ally (an ally with its own ticks).
        return in_array($this->access_level, ['ally', 'partial'], true) ? 'ally' : 'devil';
    }

    /**
     * Name for display: SeAT's character, or the public name of an external one.
     */
    public function displayName(): string
    {
        return (string) ($this->character?->name ?? $this->external_name ?? 'Character '.$this->character_id);
    }

    /**
     * Fitting IDs the pilot says this external character can fly.
     *
     * @return list<int>
     */
    public function externalFitIds(): array
    {
        return DB::table(self::EXTERNAL_FITS)
            ->where('account_character_id', $this->id)
            ->pluck('fitting_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function character(): HasOne
    {
        return $this->hasOne(CharacterInfo::class, 'character_id', 'character_id');
    }

    public function launchGroups(): BelongsToMany
    {
        return $this->belongsToMany(LaunchGroup::class, LaunchGroup::PIVOT, 'account_character_id', 'launch_group_id')
            ->orderByRaw('devilsrage_launch_groups.user_id is not null')
            ->orderBy('devilsrage_launch_groups.position')
            ->orderBy('devilsrage_launch_groups.name');
    }
}
