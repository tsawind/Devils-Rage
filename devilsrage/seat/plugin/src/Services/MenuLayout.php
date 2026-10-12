<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\Setting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * How the SeAT sidebar is arranged: which menus sit at the top, which go into a folder,
 * which are hidden. Each pilot can arrange their own; leaders keep a corp default.
 */
final class MenuLayout
{
    public const TOP = '';

    public const HIDDEN = '-';

    /** Folder names offered on the arrange page (pilots may type their own too). */
    public const FOLDERS = ['Fleet', 'Corp tools', 'Lookup', 'Account & admin'];

    /** Menus that can't be hidden, so nobody locks themselves out of this page. */
    private const KEEP = ['devilsrage'];

    /** Built-in default: menu key (or a word in its name) => folder. Unknown menus go to the top. */
    private const RULES = [
        '0home' => self::TOP,
        'devilsrage' => self::TOP,
        'fitting' => self::TOP,
        'doctrine' => self::TOP,
        'calendar' => self::TOP,
        'srp' => 'Fleet',
        'ship replacement' => 'Fleet',
        'broadcast' => 'Fleet',
        'ping' => 'Fleet',
        'structure' => 'Corp tools',
        'manager core' => 'Corp tools',
        'inventory' => 'Corp tools',
        'notification' => 'Corp tools',
        'character' => 'Lookup',
        'corporation' => 'Lookup',
        'alliance' => 'Lookup',
        'tools' => 'Lookup',
        'connector' => 'Account & admin',
        'squads' => 'Account & admin',
        'settings' => 'Account & admin',
        'api' => 'Account & admin',
        'user' => 'Account & admin',
    ];

    /**
     * Every menu this user can see: key => name, icon.
     *
     * @return array<string, array{name: string, icon: string}>
     */
    public function menus(): array
    {
        $menus = [];
        foreach ((array) config('package.sidebar', []) as $key => $menu) {
            if (! is_array($menu) || ! isset($menu['name'])) {
                continue;
            }
            if (isset($menu['permission']) && ! Gate::any((array) $menu['permission'])) {
                continue;
            }
            $menus[(string) $key] = ['name' => self::label($menu), 'icon' => (string) ($menu['icon'] ?? 'fas fa-circle')];
        }
        ksort($menus);

        return $menus;
    }

    /**
     * The layout for one user: key => [folder, order]. Folder TOP = top level, HIDDEN = hidden.
     *
     * @return array<string, array{folder: string, order: int}>
     */
    public function forUser(int $userId): array
    {
        return $this->decode(Setting::read('menu_user_'.$userId)) ?? $this->corpDefault();
    }

    /**
     * @return array<string, array{folder: string, order: int}>
     */
    public function corpDefault(): array
    {
        return $this->decode(Setting::read('menu_default')) ?? [];
    }

    public function hasOwn(int $userId): bool
    {
        return Setting::read('menu_user_'.$userId) !== null;
    }

    /**
     * @param  array<string, array{folder: string, order: int}>|null  $layout  null = back to the corp default
     */
    public function save(?int $userId, ?array $layout): void
    {
        if ($layout !== null) {
            foreach (self::KEEP as $key) {
                if (($layout[$key]['folder'] ?? null) === self::HIDDEN) {
                    $layout[$key]['folder'] = self::TOP;
                }
            }
        }

        Setting::write($userId === null ? 'menu_default' : 'menu_user_'.$userId, $layout === null ? null : json_encode($layout));
    }

    /**
     * Folder for a menu with no saved choice.
     *
     * @param  array<string, mixed>  $menu
     */
    public function defaultFolder(string $key, array $menu): string
    {
        if (array_key_exists($key, self::RULES)) {
            return self::RULES[$key];
        }

        $name = mb_strtolower(self::label($menu).' '.$key);
        foreach (self::RULES as $word => $folder) {
            if (preg_match('/\\b'.preg_quote($word, '/').'/u', $name) === 1) {
                return $folder;
            }
        }

        return self::TOP;
    }

    /**
     * @param  array<string, mixed>  $menu
     */
    public static function label(array $menu): string
    {
        if (isset($menu['label'])) {
            $text = isset($menu['plural']) ? trans_choice((string) $menu['label'], $menu['plural'] ? 0 : 1) : trans((string) $menu['label']);
            if (is_string($text) && $text !== '') {
                return Str::ucfirst($text);
            }
        }

        return (string) ($menu['name'] ?? '?');
    }

    /**
     * @return array<string, array{folder: string, order: int}>|null
     */
    private function decode(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $layout = [];
        foreach ($data as $key => $entry) {
            if (is_array($entry)) {
                $layout[(string) $key] = ['folder' => (string) ($entry['folder'] ?? self::TOP), 'order' => (int) ($entry['order'] ?? 0)];
            }
        }

        return $layout;
    }
}
