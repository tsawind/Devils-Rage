<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Seat\Web\Models\User;

/**
 * @property int $id
 * @property int $suggestion_id
 * @property int $user_id
 * @property string $body
 */
class SuggestionComment extends Model
{
    protected $table = 'devilsrage_suggestion_comments';

    protected $fillable = ['suggestion_id', 'user_id', 'body'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
