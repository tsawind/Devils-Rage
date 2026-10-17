<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Seat\Web\Models\User;

/**
 * Patch 39: an idea on the suggestions board. Anyone adds, votes and comments; leaders
 * set its status (open / planned / done / declined) with a short note.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $body
 * @property string $status
 * @property string|null $status_note
 */
class Suggestion extends Model
{
    public const VOTES = 'devilsrage_suggestion_votes';

    public const STATUSES = ['open' => 'Open', 'planned' => 'Planned', 'done' => 'Done', 'declined' => 'Declined'];

    protected $table = 'devilsrage_suggestions';

    protected $fillable = ['user_id', 'title', 'body', 'status', 'status_note'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SuggestionComment::class)->orderBy('created_at');
    }

    public function badgeClass(): string
    {
        return match ($this->status) {
            'planned' => 'badge-info',
            'done' => 'badge-success',
            'declined' => 'badge-secondary',
            default => 'badge-light',
        };
    }
}
