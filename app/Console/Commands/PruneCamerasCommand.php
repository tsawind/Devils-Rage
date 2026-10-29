<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Cameras\CamerasChangedEvent;
use App\Models\MapCamera;
use App\Models\MapCameraEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class PruneCamerasCommand extends AppCommand
{
    /**
     * @var string
     */
    protected $signature = 'app:prune-cameras';

    /**
     * @var string
     */
    protected $description = 'Patch 62: end cameras that stopped checking in, delete expired hole activity and its clips';

    public function handle(): int
    {
        $stale = MapCamera::query()
            ->whereIn('status', MapCamera::ON_STATUSES)
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subSeconds((int) config('cameras.stale_seconds') * 2)))
            ->get();
        foreach ($stale as $camera) {
            $camera->fill(['status' => 'ended', 'ended_at' => now()])->save();
        }
        foreach ($stale->pluck('map_id')->unique() as $mapId) {
            CamerasChangedEvent::dispatch((int) $mapId);
        }

        $deleted = 0;
        MapCameraEvent::query()->where('expires_at', '<', now())->chunkById(200, function ($events) use (&$deleted): void {
            foreach ($events as $event) {
                foreach ([$event->clip_path, $event->still_path] as $path) {
                    if (is_string($path) && $path !== '') {
                        Storage::disk('local')->delete($path);
                    }
                }
                $event->delete();
                $deleted++;
            }
        });
        MapCamera::query()->where('status', 'ended')->where('ended_at', '<', now()->subDays(30))->delete();

        $this->info(sprintf('Cameras ended: %d, activity removed: %d', $stale->count(), $deleted));

        return Command::SUCCESS;
    }
}
