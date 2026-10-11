<?php

declare(strict_types=1);

namespace App\Console\Commands\Seat;

use App\Console\Commands\AppCommand;
use App\Services\Seat\SeatMains;
use Throwable;

/** Patch 37: stores each character's main from SeAT (for "Alt - Main" names on the map). */
final class SyncSeatMainsCommand extends AppCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-seat-mains';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store each character\'s main from SeAT, for Alt - Main names';

    public function handle(SeatMains $seatMains): int
    {
        if (! SeatMains::configured()) {
            $this->warn('SEAT_URL and SEAT_API_TOKEN are not set: nothing to do.');

            return self::SUCCESS;
        }

        try {
            $count = $seatMains->sync();
        } catch (Throwable $throwable) {
            $this->error('SeAT could not be read: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%d characters have a main in SeAT.', $count));

        return self::SUCCESS;
    }
}
