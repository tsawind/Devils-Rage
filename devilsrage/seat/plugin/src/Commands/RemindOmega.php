<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Commands;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Services\DiscordDm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily: DM pilots 7 days and 1 day before Omega runs out on one of their accounts.
 */
class RemindOmega extends Command
{
    protected $signature = 'devilsrage:omega:remind {--dry-run : List who would get a DM without sending}';

    protected $description = 'DM pilots whose Omega runs out in 7 days or 1 day';

    public function handle(DiscordDm $dm): int
    {
        $today = Carbon::today();
        $accounts = Account::query()
            ->whereNotNull('omega_until')
            ->whereDate('omega_until', '>=', $today)
            ->whereDate('omega_until', '<=', $today->copy()->addDays(7))
            ->get();

        foreach ($accounts as $account) {
            $daysLeft = (int) $today->diffInDays($account->omega_until, false);
            $step = $daysLeft <= 1 ? 1 : 7;
            if ($account->omega_reminded !== null && $account->omega_reminded <= $step) {
                continue;
            }

            $when = match ($daysLeft) {
                0 => 'today',
                1 => 'tomorrow',
                default => 'in '.$daysLeft.' days',
            };
            $message = sprintf('⏳ Omega on your account **%s** runs out %s (%s). Update the date in SeAT → Devil\'s Rage → My accounts after you renew.', $account->nickname, $when, $account->omega_until->format('Y-m-d'));
            $this->line(sprintf('  %s (user %d): %s', $account->nickname, $account->user_id, $when));

            if (! $this->option('dry-run') && $dm->send((int) $account->user_id, $message)) {
                $account->update(['omega_reminded' => $step]);
            }
        }

        return self::SUCCESS;
    }
}
