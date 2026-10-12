<?php

declare(strict_types=1);

namespace App\Actions\Discord;

use App\Services\Seat\AssetProximity;
use App\Services\Seat\SeatClient;
use App\Services\Seat\SeatUnavailableException;

/**
 * "/plex" and "/omega": the pilot's EVE accounts as grouped in SeAT → Devil's Rage → My accounts.
 */
final readonly class ShowSeatAccountsAction
{
    public function __construct(private SeatClient $seat) {}

    public function plex(string $discordUserId): string
    {
        $data = $this->fetch($discordUserId);
        if (is_string($data)) {
            return $data;
        }

        $lines = ['**Your PLEX and ISK** · hangar PLEX and wallets from SeAT, vault PLEX as typed in My accounts'];
        $totalPlex = 0;
        $totalIsk = 0.0;
        foreach ($data['accounts'] as $row) {
            $plex = $row['plex'];
            $totalPlex += $plex['total'];
            $totalIsk += $row['isk'];
            $vault = $plex['vault'] === null ? 'vault not entered' : sprintf('%s in vault%s', number_format($plex['vault']), $plex['vault_at'] === null ? '' : ' ('.$plex['vault_at'].')');
            $months = $data['omega_plex_month'] > 0 ? $plex['total'] / $data['omega_plex_month'] : 0;
            $lines[] = sprintf(
                '• **%s** · %s PLEX (%s in hangars, %s) · %s ISK · ≈%.1f months of Omega',
                $row['nickname'],
                number_format($plex['total']),
                number_format($plex['hangar']),
                $vault,
                AssetProximity::isk($row['isk']),
                $months,
            );
        }

        foreach ($data['loose'] as $character) {
            $totalPlex += $character['plex'];
            $totalIsk += $character['isk'];
            if ($character['plex'] > 0 || $character['isk'] > 0) {
                $lines[] = sprintf('• %s (no account) · %s PLEX · %s ISK', $character['character'], number_format($character['plex']), AssetProximity::isk($character['isk']));
            }
        }

        $lines[] = sprintf('**Total** %s PLEX · %s ISK', number_format($totalPlex), AssetProximity::isk($totalIsk));
        $lines[] = '-# EVE doesn\'t share the PLEX vault: type it in SeAT → Devil\'s Rage → My accounts.';

        return implode("\n", $lines);
    }

    public function omega(string $discordUserId): string
    {
        $data = $this->fetch($discordUserId);
        if (is_string($data)) {
            return $data;
        }

        if ($data['accounts'] === []) {
            return 'You have no accounts in SeAT yet. Group your characters in SeAT → Devil\'s Rage → My accounts.';
        }

        $lines = ['**Omega and MCT**'];
        foreach ($data['accounts'] as $row) {
            $line = sprintf('• **%s** · %s', $row['nickname'], self::status('Omega', $row['omega']['state'], $row['omega']['until'], $row['omega']['days_left']));
            if ($row['mct']['slots'] > 0) {
                $line .= ' · '.self::status('MCT×'.$row['mct']['slots'], $row['mct']['state'], $row['mct']['until'], $row['mct']['days_left']);
            }
            if ($row['alpha'] !== []) {
                $line .= ' · ⚠️ training at Alpha speed: '.implode(', ', $row['alpha']);
            }
            $lines[] = $line;
        }

        $lines[] = '-# Update dates or tick auto-renew in SeAT → Devil\'s Rage → My accounts. Reminders come by DM 7 days and 1 day before.';

        return implode("\n", $lines);
    }

    private static function status(string $label, string $state, ?string $until, ?int $daysLeft): string
    {
        return match ($state) {
            'auto' => '✅ '.$label.' renews by itself',
            'ok' => sprintf('✅ %s until %s (%d days)', $label, $until, $daysLeft),
            'soon' => sprintf('⚠️ %s ends %s (%s)', $label, $until, $daysLeft === 0 ? 'today' : $daysLeft.' days'),
            'expired' => sprintf('❌ %s ended %s', $label, $until),
            default => '❔ '.$label.' date not entered',
        };
    }

    /**
     * @return array<string, mixed>|string the accounts, or the reply to send instead
     */
    private function fetch(string $discordUserId): array|string
    {
        try {
            $data = $this->seat->accounts($discordUserId);
        } catch (SeatUnavailableException $exception) {
            return 'SeAT could not be asked: '.$exception->getMessage();
        }

        return $data ?? SeatMessages::NOT_LINKED;
    }
}
