<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\AccountSetting;
use Seat\Web\Models\User;

/**
 * Patch 39: the name a person goes by ("Tsa"), set in Devil's Rage → My accounts → ⚙.
 * Empty means their main's name. Names are unique (ignoring case). Discord can show
 * "[TICKER] Main" (SeAT's own), "[TICKER] Name" or "[TICKER] Name (Main)", cut to 32.
 */
final class PersonName
{
    public const array STYLES = ['main', 'name', 'both'];

    public const MAX = 24;

    public static function of(User $user): string
    {
        $setting = AccountSetting::query()->find($user->id);
        $name = trim((string) ($setting?->person_name ?? ''));

        return $name !== '' ? $name : self::mainName($user);
    }

    public static function mainName(User $user): string
    {
        return (string) ($user->main_character?->name ?? $user->name);
    }

    /** Why a name can't be used by this user, or null when it can. */
    public static function problem(string $name, int $userId): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > self::MAX) {
            return 'Keep it to '.self::MAX.' characters.';
        }
        if (preg_match('/^[\p{L}\p{N} ._\'-]+$/u', $name) !== 1) {
            return 'Letters, digits, spaces and . _ \' - only.';
        }
        $taken = AccountSetting::query()
            ->where('user_id', '!=', $userId)
            ->whereRaw('LOWER(person_name) = ?', [mb_strtolower($name)])
            ->exists();

        return $taken ? $name.' is already someone\'s name.' : null;
    }

    /**
     * The Discord nickname for a user: SeAT's own ("[TICKER] Main") or with their name.
     */
    public static function nickname(User $user, string $default): string
    {
        $setting = AccountSetting::query()->find($user->id);
        $style = $setting?->discord_style ?? 'main';
        $name = trim((string) ($setting?->person_name ?? ''));
        if ($style === 'main' || $name === '') {
            return $default;
        }
        $prefix = preg_match('/^\[[^\]]*\]/', $default, $match) === 1 ? $match[0].' ' : '';
        $main = self::mainName($user);
        $nickname = $style === 'both' && mb_strtolower($name) !== mb_strtolower($main) ? sprintf('%s%s (%s)', $prefix, $name, $main) : $prefix.$name;

        return mb_substr($nickname, 0, 32);
    }
}
