<?php

declare(strict_types=1);

use App\Console\Commands\Characters\GetOnlineCharacterLocationsCommand;
use App\Console\Commands\Characters\GetOnlineCharactersCommand;
use App\Console\Commands\CheckConnectionAgeCommand;
use App\Console\Commands\PruneCamerasCommand;
use App\Console\Commands\Discord\PostFuelSummaryCommand;
use App\Console\Commands\Discord\PostHealthCommand;
use App\Console\Commands\GenerateStaticDataCommand;
use App\Console\Commands\GetServerStatusCommand;
use App\Console\Commands\Killmails\AnalyzeWormholeSystems;
use App\Console\Commands\Killmails\GetKillmailsForLast90DaysCommand;
use App\Console\Commands\Killmails\PurgeOldKillmailsCommand;
use App\Console\Commands\MapAccess\PurgeExpiredMapAccessCommand;
use App\Console\Commands\MapConnections\PruneUnclaimedConnectionJumpsCommand;
use App\Console\Commands\Organisations\ResolveUnnamedOrganisationsCommand;
use App\Console\Commands\Seat\SyncSeatMainsCommand;
use App\Console\Commands\Signatures\DeleteOldSignaturesCommand;
use App\Console\Commands\Signatures\ReleaseExpiredArmsCommand;
use App\Console\Commands\Skyhooks\GetRaidableSkyhooksCommand;
use App\Console\Commands\Sovereignty\GetSovereigntiesCommand;
use App\Models\MapShareLink;
use Illuminate\Queue\Console\PruneBatchesCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(GetServerStatusCommand::class)->runInBackground()->everyMinute()->withoutOverlapping();
Schedule::command(GetOnlineCharactersCommand::class)->runInBackground()->everyThirtySeconds()->withoutOverlapping(expiresAt: 60)->notDuringDowntime();
Schedule::command(GetOnlineCharacterLocationsCommand::class)->runInBackground()->everyFiveSeconds()->withoutOverlapping(expiresAt: 60)->notDuringDowntime();
Schedule::command(GetSovereigntiesCommand::class)->runInBackground()->daily()->at('15:00')->withoutOverlapping()->notDuringDowntime();
Schedule::command(GetRaidableSkyhooksCommand::class)->runInBackground()->everyFiveMinutes()->withoutOverlapping()->notDuringDowntime();
Schedule::command(GenerateStaticDataCommand::class)->runInBackground()->daily()->withoutOverlapping()->notDuringDowntime();
Schedule::command(CheckConnectionAgeCommand::class)->runInBackground()->everyTenMinutes()->withoutOverlapping();
// Patch 62: cameras that stopped checking in, and expired hole clips.
Schedule::command(PruneCamerasCommand::class)->runInBackground()->everyMinute()->withoutOverlapping();
Schedule::command(ReleaseExpiredArmsCommand::class)->runInBackground()->everyMinute()->withoutOverlapping();
Schedule::command(DeleteOldSignaturesCommand::class)->runInBackground()->everyTenMinutes()->withoutOverlapping();
Schedule::command(PruneUnclaimedConnectionJumpsCommand::class)->runInBackground()->everyTenMinutes()->withoutOverlapping();
Schedule::command(GetKillmailsForLast90DaysCommand::class)->runInBackground()->weekly();
Schedule::command(PurgeOldKillmailsCommand::class)->runInBackground()->daily();
Schedule::command(PurgeExpiredMapAccessCommand::class)->runInBackground()->everyTenMinutes()->withoutOverlapping();
Schedule::command(PruneBatchesCommand::class)->daily();
Schedule::command(AnalyzeWormholeSystems::class)->runInBackground()->daily()->withoutOverlapping();
// Patch 37: viewer links that ran out are deleted.
Schedule::call(fn () => MapShareLink::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete())->name('prune-share-links')->everyTenMinutes()->withoutOverlapping();
Schedule::command(PostFuelSummaryCommand::class)->runInBackground()->dailyAt('16:00')->withoutOverlapping();
Schedule::command(PostFuelSummaryCommand::class, ['--weekly'])->runInBackground()->weeklyOn(5, '02:00')->withoutOverlapping();
Schedule::command(PostHealthCommand::class)->runInBackground()->dailyAt('15:00')->withoutOverlapping();
Schedule::command(SyncSeatMainsCommand::class)->runInBackground()->hourly()->withoutOverlapping();
Schedule::command(ResolveUnnamedOrganisationsCommand::class)->runInBackground()->hourly()->withoutOverlapping()->notDuringDowntime();
