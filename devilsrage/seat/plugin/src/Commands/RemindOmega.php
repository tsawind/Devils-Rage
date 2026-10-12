<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Commands;

use DevilsRage\Seat\Models\Account;
use DevilsRage\Seat\Services\DiscordDm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily: DM pilots 7 days and 1 day before Omega or Multiple Character Training runs
 * out on one of their accounts. Anything set to renew by itself is skipped.
 */
class RemindOmega extends Command
{
    protected $signature = 'devilsrage:omega:remind {--dry-run : List who would get a DM without sending}';

    protected $description = 'DM pilots whose Omega or MCT runs out in 7 days or 1 day';

    public function handle(DiscordDm $dm): int
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(7);

        foreach (['omega' => 'Omega', 'mct' => 'Multiple Character Training'] as $kind => $label) {
            $accounts = Account::query()
                ->where($kind.'_auto', false)
                ->whereNotNull($kind.'_until')
                ->whereDate($kind.'_until', '>=', $today)
                ->whereDate($kind.'_until', '<=', $soon)
                ->when($kind === 'mct', fn ($query) => $query->where('mct_slots', '>', 0))
                ->get();

            foreach ($accounts as $account) {
                $until = $account->{$kind.'_until'};
                $reminded = $account->{$kind.'_reminded'};
                $daysLeft = (int) $today->diffInDays($until, false);
                $step = $daysLeft <= 1 ? 1 : 7;
                if ($reminded !== null && $reminded <= $step) {
                    continue;
                }

                $when = match ($daysLeft) {
                    0 => 'today',
                    1 => 'tomorrow',
                    default => 'in '.$daysLeft.' days',
                };
                $message = sprintf(
                    "⏳ %s on your account **%s** runs out %s (%s). After you renew, update the date (or tick auto-renew) in SeAT → Devil's Rage → My accounts.",
                    $label,
                    $account->nickname,
                    $when,
                    $until->format('Y-m-d'),
                );
                $this->line(sprintf('  %s %s (user %d): %s', $label, $account->nickname, $account->user_id, $when));

                if (! $this->option('dry-run') && $dm->send((int) $account->user_id, $message)) {
                    $account->update([$kind.'_reminded' => $step]);
                }
            }
        }

        return self::SUCCESS;
    }
}
