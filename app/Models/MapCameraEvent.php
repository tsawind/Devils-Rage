<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patch 62: something a hole camera saw (a wormhole activation, a ship appearing or vanishing
 * at the hole), with a short clip and a still. A member can turn it into a logged jump
 * ("confirmed", linked to the jump) or dismiss it. Deleted with its files at expires_at.
 *
 * @property int $id
 * @property int $map_camera_id
 * @property int $map_id
 * @property int|null $map_connection_id
 * @property int|null $signature_id Patch 62b: the unjumped hole the camera watched
 * @property string $kind activation / appeared / vanished
 * @property int $strength 0-100, how sure the camera was
 * @property string|null $clip_path
 * @property string|null $still_path
 * @property string $state new / confirmed / dismissed
 * @property string|null $ship_class
 * @property string|null $side
 * @property int|null $map_connection_jump_id
 * @property int|null $handled_by_user_id
 * @property CarbonImmutable $happened_at
 * @property CarbonImmutable $expires_at
 * @property-read MapCamera $camera
 */
final class MapCameraEvent extends Model
{
    public const array KINDS = ['activation', 'appeared', 'vanished'];

    /**
     * @return BelongsTo<MapCamera, $this>
     */
    public function camera(): BelongsTo
    {
        return $this->belongsTo(MapCamera::class, 'map_camera_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strength' => 'integer',
            'happened_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
