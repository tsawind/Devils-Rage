<?php

declare(strict_types=1);

namespace App\Console\Commands\Characters;

use App\Actions\Tracking\ProbeLocationsAction;
use App\Console\Commands\AppCommand;
use App\Jobs\Characters\DispatchCharacterStatusEvents;
use App\Jobs\Characters\UpdateCharacterLocation;
use App\Models\CharacterStatus;
use App\Support\LocationTiming;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

use function sprintf;

final class GetOnlineCharacterLocationsCommand extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:get-online-character-locations';

    /** Patch 54: rounds between per-character job batches (a round is 5 s). */
    private const int FULL_ROUND_EVERY = 3;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Checks the location of online characters and updates their status in the database';

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $characters = CharacterStatus::query()
            ->isOnline()
            ->hasRequiredScopes()
            ->get();

        CharacterStatus::query()
            ->doesntHaveRequiredScopes()
            ->update([
                'is_online' => false,
            ]);

        if ($characters->isEmpty()) {
            $this->info('No online characters to process.');

            return;
        }

        // Patch 54: everyone's location at once first; jumps reach the maps without waiting for the queue.
        $moved = app(ProbeLocationsAction::class)->handle($characters);
        $this->info(sprintf('%d characters moved (checked at once).', $moved));

        // Patch 54: the per-character jobs (ship, station, token refresh) every third round (15 s):
        // moves are already handled above, and the queue stays free for everything else.
        $round = (int) Cache::get('dr:location-rounds-counter', 0) + 1;
        Cache::forever('dr:location-rounds-counter', $round % 1_000_000);
        if ($round % self::FULL_ROUND_EVERY !== 1) {
            return;
        }

        $this->info(sprintf('Dispatching jobs for %d characters...', $characters->count()));

        $jobs = $characters->map(fn (CharacterStatus $characterStatus): UpdateCharacterLocation => new UpdateCharacterLocation($characterStatus->id));

        $batch = Bus::batch($jobs->all())
            ->name('Update Character Locations')
            ->finally(function (Batch $batch): void {
                // Patch 54: how long the round took (Server workflow → mapper-timing).
                LocationTiming::round($batch->totalJobs, $batch->createdAt);
                // Dispatch a job to handle event dispatching after all character updates are complete
                DispatchCharacterStatusEvents::dispatch();
            })
            ->dispatch();

        $this->info(sprintf('Batch dispatched with ID: %s', $batch->id));
        $this->info('Characters will be processed in parallel.');
    }
}
