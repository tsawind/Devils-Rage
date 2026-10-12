<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Actions\Discord\ShowHealthAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('devilsrage:backup-done')]
#[Description('Record that the nightly server backup finished (called by devilsrage/backup.sh); /health warns when it stops.')]
final class BackupDoneCommand extends Command
{
    public function handle(): int
    {
        Cache::forever(ShowHealthAction::BACKUP_KEY, now()->toIso8601String());
        $this->components->info('Backup recorded.');

        return self::SUCCESS;
    }
}
