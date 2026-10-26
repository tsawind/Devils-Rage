<?php

declare(strict_types=1);

namespace App\Console\Commands\Backups;

use App\Actions\Discord\ShowHealthAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devilsrage:backup-done')]
#[Description('Record that the nightly server backup finished (called by devilsrage/backup.sh); /health warns when it stops.')]
final class BackupDoneCommand extends Command
{
    public function handle(): int
    {
        ShowHealthAction::recordBackup();
        $this->components->info('Backup recorded.');

        return self::SUCCESS;
    }
}
