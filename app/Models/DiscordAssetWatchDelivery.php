<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks that an "/assets watch" DM went out for one exit placement, so it never repeats.
 *
 * @property int $id
 * @property int $discord_asset_watch_id
 * @property int $map_solarsystem_id
 * @property-read string|CarbonImmutable $created_at
 * @property-read string|CarbonImmutable $updated_at
 */
final class DiscordAssetWatchDelivery extends Model {}
