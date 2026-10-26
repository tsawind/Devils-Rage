<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatForbiddenException;
use App\Services\Seat\SeatUnavailableException;
use Illuminate\Support\Carbon;
use App\Models\AppSetting;

/**
 * "/health" and the daily health post: SeAT logins of the CEO and directors (structure
 * pings depend on them), characters whose login expired, failed jobs, last backup.
 */
final readonly class ShowHealthAction
{
    public const string BACKUP_KEY = 'devilsrage.backup.last';

    private const int BACKUP_LATE_HOURS = 36;

    private const int FAILED_JOBS_WARN = 50;

    public function __construct(private SeatClient $seat) {}

    /** Records that the nightly backup finished. Kept in the database: Redis restarts used to forget it. */
    public static function recordBackup(): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::BACKUP_KEY], ['value' => now()->toIso8601String(), 'updated_by_name' => 'backup.sh']);
    }

    public static function lastBackup(): ?Carbon
    {
        $value = AppSetting::query()->where('key', self::BACKUP_KEY)->value('value');

        return is_string($value) ? Carbon::parse($value) : null;
    }

    /**
     * @return array{text: string, problems: int}
     *
     * @throws SeatUnavailableException when SeAT can't be asked (the daily post says so)
     */
    public function report(string $discordUserId): array
    {
        try {
            $health = $this->seat->health($discordUserId);
        } catch (SeatForbiddenException $exception) {
            return ['text' => $exception->getMessage(), 'problems' => 0];
        }

        if ($health === null) {
            return ['text' => SeatMessages::NOT_LINKED, 'problems' => 0];
        }

        $problems = [];
        foreach ($health['leaders'] as $leader) {
            $problem = match ($leader['status']) {
                'missing' => 'has never logged in to SeAT',
                'expired' => 'SeAT login expired: log in again on SeAT',
                'stale' => 'SeAT login hasn\'t refreshed for a day',
                default => null,
            };
            if ($problem !== null) {
                $problems[] = sprintf('• **%s** (%s): %s. Structure attack and fuel pings depend on it.', $leader['name'], $leader['role'], $problem);
            }
        }

        if ($health['expired_count'] > 0) {
            $problems[] = sprintf(
                '• %d character%s in accounts need%s to log in to SeAT again: %s%s',
                $health['expired_count'],
                $health['expired_count'] === 1 ? '' : 's',
                $health['expired_count'] === 1 ? 's' : '',
                implode(', ', $health['expired']),
                $health['expired_count'] > count($health['expired']) ? ' …' : '',
            );
        }

        if (($health['failed_jobs'] ?? 0) >= self::FAILED_JOBS_WARN) {
            $problems[] = sprintf('• %d SeAT jobs failed in the last day (usually ESI hiccups; many means something is wrong).', $health['failed_jobs']);
        }

        $backupAt = self::lastBackup();
        if ($backupAt === null) {
            $problems[] = '• No nightly backup has reported in yet (backup.sh calls `devilsrage:backup-done`).';
        } elseif ($backupAt->lt(Carbon::now()->subHours(self::BACKUP_LATE_HOURS))) {
            $problems[] = sprintf('• The last backup was %s.', $backupAt->diffForHumans());
        }

        $leaders = count($health['leaders']);
        $okLine = sprintf(
            '%d leader login%s working · backup %s · %d failed jobs in a day',
            $leaders - count(array_filter($health['leaders'], fn (array $leader): bool => $leader['status'] !== 'ok')),
            $leaders === 1 ? '' : 's',
            $backupAt === null ? 'never' : $backupAt->diffForHumans(),
            (int) ($health['failed_jobs'] ?? 0),
        );

        return [
            'text' => $problems === []
                ? "✅ **SeAT health: all good**\n".$okLine
                : "⚠️ **SeAT health**\n".implode("\n", $problems)."\n-# ".$okLine,
            'problems' => count($problems),
        ];
    }

    public function handle(string $discordUserId): string
    {
        try {
            return $this->report($discordUserId)['text'];
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }
    }
}
