<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Commands;

use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Pi\PiData;
use DevilsRage\Seat\Pi\PiDigest;
use DevilsRage\Seat\Services\DiscordDm;
use Illuminate\Console\Command;
use Seat\Web\Models\User;

/**
 * Patch 50, hourly: remember every extractor program (SeAT keeps only the current one, we need
 * earlier ones to see a spot running out), then send the PI message to pilots who asked for it,
 * at their chosen hour, once a day at most.
 */
class PiHourly extends Command
{
    protected $signature = 'devilsrage:pi:hourly {--dry-run : Show who would get a message without sending}';

    protected $description = 'Record PI extractor programs and send opted-in pilots their daily PI message';

    public function handle(DiscordDm $dm, PiDigest $digest): int
    {
        $new = (new PiData())->snapshot();
        $this->line("Extractor programs recorded: {$new} new");

        $settings = AccountSetting::query()->whereIn('pi_alerts', ['due', 'stopped'])->get();
        foreach ($settings as $setting) {
            if (! $digest->isTime($setting)) {
                continue;
            }
            $user = User::query()->find($setting->user_id);
            if ($user === null) {
                continue;
            }
            $message = $digest->message($user, $setting);
            // Nothing to say today counts as done for today too (no second try at a later hour).
            if ($message !== null) {
                $this->line("  PI message for user {$user->id}");
                if ($this->option('dry-run')) {
                    continue;
                }
                if (! $dm->send((int) $user->id, $message)) {
                    $this->warn("  couldn't DM user {$user->id} (no Discord link?)");
                }
            }
            if (! $this->option('dry-run')) {
                $setting->update(['pi_alert_sent' => $digest->today($setting)]);
            }
        }

        return self::SUCCESS;
    }
}
