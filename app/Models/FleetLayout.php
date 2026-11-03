<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FleetLayoutFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patch 67: a saved fleet layout: wings, each with an optional commander and squads, each squad
 * with an optional commander and members. Characters are stored as id + name.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $shared
 * @property array{wings: list<array{name: string, commander: array{id: int, name: string}|null, squads: list<array{name: string, commander: array{id: int, name: string}|null, members: list<array{id: int, name: string}>}>}>} $layout
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(FleetLayoutFactory::class)]
final class FleetLayout extends Model
{
    /** @use HasFactory<FleetLayoutFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'shared', 'layout'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Your own layouts and everyone's shared ones.
     *
     * @param  Builder<FleetLayout>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $inner) => $inner->where('user_id', $user->id)->orWhere('shared', true));
    }

    /**
     * Every character in the layout, in order (wing commander, then each squad's commander and members).
     *
     * @return list<int>
     */
    public function characterIds(): array
    {
        $ids = [];
        foreach ($this->layout['wings'] ?? [] as $wing) {
            if (isset($wing['commander']['id'])) {
                $ids[] = (int) $wing['commander']['id'];
            }
            foreach ($wing['squads'] as $squad) {
                if (isset($squad['commander']['id'])) {
                    $ids[] = (int) $squad['commander']['id'];
                }
                foreach ($squad['members'] as $member) {
                    $ids[] = (int) $member['id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    protected function casts(): array
    {
        return [
            'shared' => 'boolean',
            'layout' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
