<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Pi;

use DevilsRage\Seat\Models\AccountSetting;
use Illuminate\Support\Carbon;
use Seat\Web\Models\User;

/**
 * Patch 50: one PI message a day at most, only for pilots who picked it on the PI screen.
 * "due" = a PI run is due; "stopped" = only when something has already stopped.
 */
final class PiDigest
{
    public const MODES = ['never', 'due', 'stopped'];

    /**
     * The pilot's PI run right now.
     *
     * @return array<string, mixed>
     */
    public function runFor(User $user): array
    {
        $ids = array_map('intval', $user->associatedCharacterIds());
        $data = new PiData();
        $planets = $data->planets($ids);
        $planner = new PiPlanner($data->types(), $data->history($ids), now()->getTimestamp());
        $characters = [];
        foreach ($data->loginOrder((int) $user->id, $ids) as $id => $name) {
            $characters[$id] = ['name' => $name, 'planets' => $planets[$id] ?? []];
        }

        return $planner->run($characters);
    }

    /**
     * The message for this pilot, or null when there's nothing worth saying under their choice
     * ($force: say it anyway, for "Send me a test").
     */
    public function message(User $user, AccountSetting $setting, bool $force = false): ?string
    {
        $run = $this->runFor($user);
        $stopped = 0;
        $lines = [];
        foreach ($run['characters'] as $character) {
            $planets = [];
            foreach ($character['visits'] as $visit) {
                $isStopped = $visit['planet']['status'] === 'stopped';
                $stopped += $isStopped ? 1 : 0;
                $planets[] = $visit['planet']['planet']['name'].($isStopped ? ' (stopped)' : '');
            }
            $lines[] = '• **'.$character['name'].'**: '.implode(', ', $planets);
        }
        $mode = $setting->pi_alerts ?? 'never';
        $worth = match ($mode) {
            'due' => $lines !== [],
            'stopped' => $stopped > 0,
            default => false,
        };
        if (! $worth && ! $force) {
            return null;
        }

        $tz = $this->timezone($setting);
        $url = route('devilsrage.pi');
        if ($lines === []) {
            $next = $run['next_stop'] ? ' Next extractor stops '.Carbon::createFromTimestamp($run['next_stop'], $tz)->format('D H:i').' (your time).' : '';

            return "🪐 PI: nothing to do right now.{$next}\n<{$url}>";
        }
        $head = sprintf('🪐 PI run due · about %d min%s', $run['minutes'], $stopped > 0 ? ' · '.$stopped.' stopped' : '');
        $deadline = $run['deadline'] ? "\nDo it before ".Carbon::createFromTimestamp($run['deadline'], $tz)->format('D H:i').' (your time).' : '';

        return $head."\n".implode("\n", $lines).$deadline."\nWhat to do on each planet: <{$url}>";
    }

    /** Whether it's this pilot's hour and they haven't had today's message yet. */
    public function isTime(AccountSetting $setting, ?Carbon $now = null): bool
    {
        if (! in_array($setting->pi_alerts, ['due', 'stopped'], true)) {
            return false;
        }
        $local = ($now ?? now())->copy()->setTimezone($this->timezone($setting));
        if ($local->hour !== (int) $setting->pi_alert_hour) {
            return false;
        }

        return $setting->pi_alert_sent === null || $setting->pi_alert_sent->toDateString() !== $local->toDateString();
    }

    public function today(AccountSetting $setting): string
    {
        return now()->setTimezone($this->timezone($setting))->toDateString();
    }

    private function timezone(AccountSetting $setting): string
    {
        $tz = (string) ($setting->pi_timezone ?? '');

        return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }
}
