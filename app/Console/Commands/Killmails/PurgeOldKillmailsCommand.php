<?php

declare(strict_types=1);

namespace App\Console\Commands\Killmails;

use App\Console\Commands\AppCommand;
use App\Models\Killmail;
use Illuminate\Container\Attributes\Config;

final class PurgeOldKillmailsCommand extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:purge-old-killmails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge old killmails from the database';

    /**
     * Execute the console command.
     */
    public function handle(
        #[Config('services.zkillboard.max_age_days')] int $max_age_days,
        #[Config('services.zkillboard.kspace_max_age_days')] int $kspace_max_age_days,
    ): int {
        $deleted = Killmail::query()
            ->where('time', '<', now()->subDays($max_age_days))
            ->delete();

        // Devil's Rage: known-space kills are only needed while they are recent (alerts, kill
        // lists); threat analysis only reads wormhole space. Deleted in batches (16k kills a day).
        $kspace = 0;
        do {
            $batch = Killmail::query()
                ->whereNotBetween('solarsystem_id', [self::FIRST_WORMHOLE_SYSTEM, self::LAST_WORMHOLE_SYSTEM])
                ->where('time', '<', now()->subDays($kspace_max_age_days))
                ->limit(20_000)
                ->delete();
            $kspace += $batch;
        } while ($batch > 0);

        $this->info("Deleted $deleted killmails older than $max_age_days days and $kspace known-space killmails older than $kspace_max_age_days days.");

        return 0;
    }

    private const int FIRST_WORMHOLE_SYSTEM = 31_000_000;

    private const int LAST_WORMHOLE_SYSTEM = 31_999_999;
}
