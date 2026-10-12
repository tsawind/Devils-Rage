<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Http\Composers;

use DevilsRage\Seat\Services\MenuLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request;

/**
 * Runs after SeAT's own sidebar composer: puts menus into folders, orders and hides
 * them as the pilot (or the corp default) arranged on Devil's Rage → Menu.
 */
final class FolderedSidebar
{
    public function __construct(private readonly MenuLayout $layout) {}

    public function compose(View $view): void
    {
        $menu = $view->getData()['menu'] ?? null;
        $user = auth()->user();
        if (! $menu instanceof Collection || $user === null) {
            return;
        }

        $layout = $this->layout->forUser((int) $user->id);
        $segment = (string) Request::segment(1);
        $top = [];
        $folders = [];
        $position = 0;

        foreach ($menu as $key => $entry) {
            $key = (string) $key;
            $position++;
            $folder = $layout[$key]['folder'] ?? $this->layout->defaultFolder($key, $entry);
            $order = $layout[$key]['order'] ?? 1000 + $position;

            // Custom links (Settings → SeAT) can't nest: they always stay on top.
            if (($entry['route_segment'] ?? '') === 'custom') {
                $folder = $folder === MenuLayout::HIDDEN ? MenuLayout::HIDDEN : MenuLayout::TOP;
            }

            if ($folder === MenuLayout::HIDDEN) {
                continue;
            }

            if ($folder === MenuLayout::TOP) {
                $top[] = ['order' => $order, 'entry' => $entry];

                continue;
            }

            $folders[$folder] ??= ['order' => $order, 'entries' => []];
            $folders[$folder]['order'] = min($folders[$folder]['order'], $order);
            $folders[$folder]['entries'][] = ['order' => $order, 'entry' => $this->withAllowedEntries($entry)];
        }

        foreach ($folders as $name => $folder) {
            usort($folder['entries'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            $children = array_column($folder['entries'], 'entry');
            $open = collect($children)->contains(fn (array $child): bool => ($child['route_segment'] ?? null) === $segment);
            $top[] = ['order' => $folder['order'], 'entry' => [
                'name' => $name,
                'icon' => $open ? 'fas fa-folder-open' : 'fas fa-folder',
                // The folder opens when the page shown belongs to one of its menus.
                'route_segment' => $open ? $segment : 'dr-folder-'.md5($name),
                'entries' => $children,
            ]];
        }

        usort($top, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        $view->with('menu', collect(array_column($top, 'entry')));
    }

    /**
     * Inside a folder SeAT draws a menu's links without checking their permissions,
     * so links the pilot may not open are dropped here.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withAllowedEntries(array $entry): array
    {
        if (! isset($entry['entries']) || ! is_array($entry['entries'])) {
            return $entry;
        }

        $entry['entries'] = array_values(array_filter(
            $entry['entries'],
            fn ($item): bool => is_array($item) && (! isset($item['permission']) || Gate::any((array) $item['permission'])),
        ));

        return $entry;
    }
}
