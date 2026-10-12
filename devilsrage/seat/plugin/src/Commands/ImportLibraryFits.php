<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Commands;

use CryptaTech\Seat\Fitting\Events\FittingUpdated;
use CryptaTech\Seat\Fitting\Models\Fitting;
use CryptaTech\Seat\Fitting\Models\FittingItem;
use DevilsRage\Seat\Fits\EsiFitConverter;
use DevilsRage\Seat\Models\FitImport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Fittings\CharacterFitting;
use Seat\Eveapi\Models\RefreshToken;

/**
 * Copies the corp fit library (the personal in-game fits of the library character, by
 * default the CEO) into seat-fitting, so doctrines, the doctrine report ("who can fly
 * it"), inventory stock and pings all use the same fits as in game.
 *
 * - New library fit      -> new fit in SeAT (an existing SeAT fit with the same hull + name is adopted).
 * - Changed library fit  -> the same SeAT fit is updated (doctrines keep it).
 * - Removed library fit  -> removed from SeAT (only fits this import created/adopted).
 * Fits added by hand in SeAT are never touched.
 */
class ImportLibraryFits extends Command
{
    protected $signature = 'devilsrage:fits:import {--dry-run : Show what would change without changing anything}';

    protected $description = "Import the corp fit library (library character's in-game fits) into seat-fitting";

    public function handle(EsiFitConverter $converter): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = trim((string) config('devilsrage.fit_prefix', ''));
        $libraryIds = $this->libraryCharacterIds();

        if ($libraryIds === []) {
            $this->warn('No fit library character found. Set DR_FIT_LIBRARY_CHARACTERS in .env, or make sure the CEO has logged into SeAT.');

            return self::SUCCESS;
        }

        $names = CharacterInfo::query()->whereIn('character_id', $libraryIds)->pluck('name', 'character_id');
        $this->info('Fit library: '.collect($libraryIds)->map(fn (int $id): string => (string) ($names[$id] ?? $id))->implode(', ')
            .($prefix === '' ? ' (all personal fits)' : " (fits starting with \"{$prefix}\")"));

        $fitsByCharacter = CharacterFitting::query()
            ->with('items')
            ->whereIn('character_id', $libraryIds)
            ->orderBy('fitting_id')
            ->get()
            ->groupBy('character_id');

        $wanted = $this->wantedFits($libraryIds, $fitsByCharacter, $converter, $prefix);
        $counts = ['created' => 0, 'adopted' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped items' => 0];

        foreach ($wanted as $key => $fit) {
            $counts['skipped items'] += $fit['skipped'];
            $link = FitImport::query()->where('match_key', $key)->first();
            $fitting = $link !== null ? Fitting::query()->find($link->fitting_id) : null;

            if ($fitting !== null && $link->fingerprint === $fit['fingerprint']) {
                $counts['unchanged']++;

                continue;
            }

            $action = 'updated';
            if ($fitting === null) {
                $fitting = Fitting::query()
                    ->where('ship_type_id', $fit['ship_type_id'])
                    ->where('name', $fit['name'])
                    ->whereNotIn('fitting_id', FitImport::query()->select('fitting_id'))
                    ->first();
                $action = $fitting !== null ? 'adopted' : 'created';
            }

            $counts[$action]++;
            $this->line(sprintf('  %-8s %s', $action, $fit['name']));

            if (! $dryRun) {
                $saved = $this->writeFit($fitting, $key, $fit);
                FittingUpdated::dispatch($saved);
            }
        }

        $counts['removed'] = $this->removeDroppedFits($wanted, $fitsByCharacter, $libraryIds, $dryRun);

        $this->info(($dryRun ? 'Dry run, nothing changed. ' : '').collect($counts)
            ->map(fn (int $count, string $label): string => "{$label}: {$count}")
            ->implode(', '));

        return self::SUCCESS;
    }

    /**
     * Library fits keyed by hull + name. When two library characters have the same fit,
     * the first character in the list wins.
     *
     * @param  list<int>  $libraryIds
     * @param  Collection<int|string, Collection<int, CharacterFitting>>  $fitsByCharacter
     * @return array<string, array{name: string, description: string, ship_type_id: int, items: list<array{type_id: int, flag: int, quantity: int}>, skipped: int, fingerprint: string, source_character_id: int, source_fitting_id: int}>
     */
    private function wantedFits(array $libraryIds, Collection $fitsByCharacter, EsiFitConverter $converter, string $prefix): array
    {
        $wanted = [];

        foreach ($libraryIds as $characterId) {
            foreach ($fitsByCharacter->get($characterId, collect()) as $esiFit) {
                $name = $converter->libraryName((string) $esiFit->name, $prefix);
                if ($name === null) {
                    continue;
                }

                $shipTypeId = (int) $esiFit->ship_type_id;
                $key = $converter->matchKey($shipTypeId, $name);
                if (isset($wanted[$key])) {
                    continue;
                }

                $converted = $converter->convert($esiFit->items->map(fn ($item): array => [
                    'type_id' => $item->type_id,
                    'flag' => $item->flag,
                    'quantity' => $item->quantity,
                ]));
                $description = (string) $esiFit->description;

                $wanted[$key] = [
                    'name' => $name,
                    'description' => $description,
                    'ship_type_id' => $shipTypeId,
                    'items' => $converted['items'],
                    'skipped' => $converted['skipped'],
                    'fingerprint' => $converter->fingerprint($shipTypeId, $name, $description, $converted['items']),
                    'source_character_id' => (int) $esiFit->character_id,
                    'source_fitting_id' => (int) $esiFit->fitting_id,
                ];
            }
        }

        return $wanted;
    }

    /**
     * @param  array{name: string, description: string, ship_type_id: int, items: list<array{type_id: int, flag: int, quantity: int}>, fingerprint: string, source_character_id: int, source_fitting_id: int}  $fit
     */
    private function writeFit(?Fitting $fitting, string $key, array $fit): Fitting
    {
        return DB::transaction(function () use ($fitting, $key, $fit): Fitting {
            $fitting ??= new Fitting;
            $fitting->name = $fit['name'];
            $fitting->description = $fit['description'];
            $fitting->ship_type_id = $fit['ship_type_id'];
            $fitting->save();

            FittingItem::query()->where('fitting_id', $fitting->fitting_id)->delete();

            $now = Carbon::now();
            FittingItem::query()->insert(array_map(fn (array $item): array => $item + [
                'fitting_id' => $fitting->fitting_id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $fit['items']));

            FitImport::query()->where('fitting_id', $fitting->fitting_id)->where('match_key', '!=', $key)->delete();
            FitImport::query()->updateOrCreate(['match_key' => $key], [
                'fitting_id' => $fitting->fitting_id,
                'source_character_id' => $fit['source_character_id'],
                'source_fitting_id' => $fit['source_fitting_id'],
                'fingerprint' => $fit['fingerprint'],
            ]);

            $fitting->unsetRelations();

            return $fitting;
        });
    }

    /**
     * Removes imported fits that are no longer in the library. A library character whose
     * fit list is empty is treated as "not synced (yet)" and nothing of theirs is removed;
     * fits from characters that are no longer library characters are kept as well.
     *
     * @param  array<string, mixed>  $wanted
     * @param  Collection<int|string, Collection<int, CharacterFitting>>  $fitsByCharacter
     * @param  list<int>  $libraryIds
     */
    private function removeDroppedFits(array $wanted, Collection $fitsByCharacter, array $libraryIds, bool $dryRun): int
    {
        $syncedIds = collect($libraryIds)->filter(fn (int $id): bool => $fitsByCharacter->get($id, collect())->isNotEmpty());

        $dropped = FitImport::query()
            ->whereIn('source_character_id', $syncedIds->all())
            ->whereNotIn('match_key', array_keys($wanted))
            ->get();

        foreach ($dropped as $link) {
            $this->line(sprintf('  %-8s %s', 'removed', Fitting::query()->whereKey($link->fitting_id)->value('name') ?? "#{$link->fitting_id}"));

            if (! $dryRun) {
                Fitting::query()->whereKey($link->fitting_id)->delete();
                $link->delete();
            }
        }

        return $dropped->count();
    }

    /**
     * DR_FIT_LIBRARY_CHARACTERS (names or IDs), or else the CEO of the corporation most
     * SeAT pilots are in, when that CEO has logged into SeAT.
     *
     * @return list<int>
     */
    private function libraryCharacterIds(): array
    {
        $configured = trim((string) config('devilsrage.fit_library_characters', ''));

        if ($configured !== '') {
            $ids = [];
            foreach (array_filter(array_map('trim', explode(',', $configured))) as $entry) {
                $id = ctype_digit($entry)
                    ? (int) $entry
                    : CharacterInfo::query()->where('name', $entry)->value('character_id');

                if ($id === null) {
                    $this->warn("Fit library character \"{$entry}\" is not in SeAT; skipped.");

                    continue;
                }

                $ids[] = (int) $id;
            }

            return array_values(array_unique($ids));
        }

        $mainCorporationId = CharacterAffiliation::query()
            ->whereIn('character_id', RefreshToken::query()->select('character_id'))
            ->selectRaw('corporation_id, count(*) as pilots')
            ->groupBy('corporation_id')
            ->orderByDesc('pilots')
            ->value('corporation_id');

        $ceoId = CorporationInfo::query()->where('corporation_id', $mainCorporationId)->value('ceo_id');

        if ($ceoId === null || ! RefreshToken::query()->where('character_id', $ceoId)->exists()) {
            return [];
        }

        return [(int) $ceoId];
    }
}
