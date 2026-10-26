<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\IntelEntry;
use App\Models\IntelStructure;
use App\Models\MapSolarsystem;
use App\Models\User;
use App\Support\DscanSummary;
use App\Support\StructureScan;
use App\Support\SystemAssessment;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patch 55: a system's intel (per map, like its notes): structures / POS, D-scans, fits,
 * screenshots and notes, every change kept in its log, and who lives there (from the kills
 * the mapper keeps). Read by anyone who sees the map, written by those who may edit it.
 */
final class SystemIntelController extends Controller
{
    private const string DISK = 'local';

    public function show(MapSolarsystem $mapSolarsystem): JsonResponse
    {
        Gate::authorize('view', $mapSolarsystem);

        $structures = IntelStructure::query()
            ->where('map_id', $mapSolarsystem->map_id)
            ->where('solarsystem_id', $mapSolarsystem->solarsystem_id)
            ->orderByDesc('seen_at')
            ->get();
        $entries = IntelEntry::query()
            ->where('map_id', $mapSolarsystem->map_id)
            ->where('solarsystem_id', $mapSolarsystem->solarsystem_id)
            ->latest('id')
            ->limit(300)
            ->get();

        return response()->json([
            'structures' => $structures->map(fn (IntelStructure $structure): array => $this->structurePayload($structure))->all(),
            'entries' => $entries->map(fn (IntelEntry $entry): array => $this->entryPayload($mapSolarsystem, $entry))->all(),
            'assessment' => SystemAssessment::for($mapSolarsystem->solarsystem_id),
            'kinds' => IntelStructure::KINDS,
            'states' => IntelStructure::STATES,
        ]);
    }

    public function storeStructure(Request $request, MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $validated = $request->validate($this->structureRules());
        $who = $this->who($user);

        $structure = new IntelStructure([...$this->structureFields($validated), 'map_id' => $mapSolarsystem->map_id, 'solarsystem_id' => $mapSolarsystem->solarsystem_id, 'created_by_name' => $who, 'updated_by_name' => $who]);
        $structure->save();
        $this->log($mapSolarsystem, 'structure', sprintf('Added %s', $this->label($structure)), $who, $structure->id, ['action' => 'added', 'state' => $structure->state]);

        return response()->json(['structure' => $this->structurePayload($structure)], 201);
    }

    public function updateStructure(Request $request, MapSolarsystem $mapSolarsystem, IntelStructure $structure, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $this->belongs($mapSolarsystem, $structure);
        $validated = $request->validate($this->structureRules());
        $who = $this->who($user);

        $structure->fill([...$this->structureFields($validated), 'updated_by_name' => $who]);
        $changed = array_keys(array_diff_key($structure->getDirty(), ['updated_by_name' => true, 'seen_at' => true]));
        $structure->save();
        $what = $changed === [] ? 'seen again' : 'changed '.implode(', ', array_map(fn (string $field): string => str_replace('_', ' ', $field), $changed));
        $this->log($mapSolarsystem, 'structure', sprintf('%s: %s', $this->label($structure), $what), $who, $structure->id, ['action' => 'updated', 'state' => $structure->state, 'changed' => $changed]);

        return response()->json(['structure' => $this->structurePayload($structure)]);
    }

    public function destroyStructure(MapSolarsystem $mapSolarsystem, IntelStructure $structure, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $this->belongs($mapSolarsystem, $structure);
        $structure->delete();
        $this->log($mapSolarsystem, 'structure', sprintf('Removed %s', $this->label($structure)), $this->who($user), $structure->id, ['action' => 'removed']);

        return response()->json(['ok' => true]);
    }

    /**
     * Patch 57: structures from text copied in EVE (a D-scan or overview rows): new ones are
     * added, ones already here (same kind and name) are marked seen again, owner ticker filled in.
     */
    public function importStructures(Request $request, MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $validated = $request->validate(['text' => ['required', 'string', 'max:200000']]);

        $result = $this->importFrom($mapSolarsystem, (string) $validated['text'], $this->who($user));

        return response()->json([
            'added' => $result['added'],
            'updated' => $result['updated'],
            'structures' => array_map(fn (IntelStructure $structure): array => $this->structurePayload($structure), $result['structures']),
        ], $result['added'] === [] ? 200 : 201);
    }

    public function storeEntry(Request $request, MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['dscan', 'fit', 'note'])],
            'body' => ['required', 'string', 'max:20000'],
            'intel_structure_id' => ['nullable', 'integer'],
        ]);
        $structureId = $this->structureIdFor($mapSolarsystem, $validated['intel_structure_id'] ?? null);
        $body = mb_trim(str_replace("\r\n", "\n", (string) $validated['body']));
        $data = $validated['kind'] === 'dscan' ? DscanSummary::parse($body) : null;
        $entry = $this->log($mapSolarsystem, (string) $validated['kind'], $body, $this->who($user), $structureId, $data);

        // Patch 57: structures in a pasted D-scan are added (or marked seen) as well.
        if ($validated['kind'] === 'dscan') {
            $this->importFrom($mapSolarsystem, $body, $this->who($user));
        }

        if ($validated['kind'] === 'fit' && $structureId !== null) {
            IntelStructure::query()->whereKey($structureId)->update(['fit' => $body, 'updated_by_name' => $this->who($user)]);
        }

        return response()->json(['entry' => $this->entryPayload($mapSolarsystem, $entry)], 201);
    }

    public function storeScreenshot(Request $request, MapSolarsystem $mapSolarsystem, #[CurrentUser] User $user): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:300'],
            'intel_structure_id' => ['nullable', 'integer'],
        ]);
        $structureId = $this->structureIdFor($mapSolarsystem, $validated['intel_structure_id'] ?? null);
        $file = $request->file('image');
        $path = $file->storeAs(sprintf('intel/%d', $mapSolarsystem->map_id), Str::uuid()->toString().'.'.$file->extension(), self::DISK);
        $entry = $this->log($mapSolarsystem, 'screenshot', (string) ($validated['caption'] ?? ''), $this->who($user), $structureId, null, $path ?: null);

        return response()->json(['entry' => $this->entryPayload($mapSolarsystem, $entry)], 201);
    }

    /**
     * Patch 57: a D-scan, fit, note or screenshot added by mistake can be deleted (its picture too).
     * Structure log lines stay: they are the history.
     */
    public function destroyEntry(MapSolarsystem $mapSolarsystem, IntelEntry $entry): JsonResponse
    {
        Gate::authorize('update', $mapSolarsystem);
        abort_unless($entry->map_id === $mapSolarsystem->map_id && $entry->solarsystem_id === $mapSolarsystem->solarsystem_id, 404);
        abort_if($entry->kind === 'structure', 422, 'Structure history stays.');

        if ($entry->image_path) {
            Storage::disk(self::DISK)->delete((string) $entry->image_path);
        }
        $entry->delete();

        return response()->json(['ok' => true]);
    }

    public function screenshot(MapSolarsystem $mapSolarsystem, IntelEntry $entry): StreamedResponse
    {
        Gate::authorize('view', $mapSolarsystem);
        abort_unless($entry->map_id === $mapSolarsystem->map_id && $entry->solarsystem_id === $mapSolarsystem->solarsystem_id && $entry->image_path, 404);
        abort_unless(Storage::disk(self::DISK)->exists((string) $entry->image_path), 404);

        return Storage::disk(self::DISK)->response((string) $entry->image_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * @return array<string, mixed>
     */
    private function structureRules(): array
    {
        return [
            'kind' => ['required', Rule::in(IntelStructure::KINDS)],
            'name' => ['nullable', 'string', 'max:120'],
            'owner' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'state' => ['required', Rule::in(IntelStructure::STATES)],
            'vulnerable_window' => ['nullable', 'string', 'max:60'],
            'timer_at' => ['nullable', 'date'],
            'fit' => ['nullable', 'string', 'max:10000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'seen_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function structureFields(array $validated): array
    {
        $fields = array_intersect_key($validated, array_flip(['kind', 'name', 'owner', 'location', 'state', 'vulnerable_window', 'timer_at', 'fit', 'note']));
        $fields['seen_at'] = isset($validated['seen_at']) ? $validated['seen_at'] : now();

        return $fields;
    }

    /**
     * @return array{added: list<int>, updated: list<int>, structures: list<IntelStructure>}
     */
    private function importFrom(MapSolarsystem $mapSolarsystem, string $text, string $who): array
    {
        $added = [];
        $updated = [];
        $structures = [];
        $existing = IntelStructure::query()
            ->where('map_id', $mapSolarsystem->map_id)
            ->where('solarsystem_id', $mapSolarsystem->solarsystem_id)
            ->get();

        foreach (array_slice(StructureScan::parse($text), 0, 50) as $found) {
            $name = $found['name'] !== null ? mb_substr($found['name'], 0, 120) : null;
            $match = $existing->first(fn (IntelStructure $structure): bool => $structure->kind === $found['kind']
                && mb_strtolower((string) $structure->name) === mb_strtolower((string) $name));

            if ($match instanceof IntelStructure) {
                $match->fill(['seen_at' => now(), 'updated_by_name' => $who]);
                if ($found['owner'] !== null && $match->owner === null) {
                    $match->owner = $found['owner'];
                }
                $match->save();
                $this->log($mapSolarsystem, 'structure', sprintf('%s: seen again (copied from EVE)', $this->label($match)), $who, $match->id, ['action' => 'seen', 'state' => $match->state]);
                $updated[] = $match->id;
                $structures[] = $match;

                continue;
            }

            $structure = new IntelStructure([
                'map_id' => $mapSolarsystem->map_id,
                'solarsystem_id' => $mapSolarsystem->solarsystem_id,
                'kind' => $found['kind'],
                'name' => $name,
                'owner' => $found['owner'],
                'state' => 'online',
                'seen_at' => now(),
                'created_by_name' => $who,
                'updated_by_name' => $who,
            ]);
            $structure->save();
            $existing->push($structure);
            $this->log($mapSolarsystem, 'structure', sprintf('Added %s (copied from EVE)', $this->label($structure)), $who, $structure->id, ['action' => 'added', 'state' => $structure->state, 'type' => $found['type']]);
            $added[] = $structure->id;
            $structures[] = $structure;
        }

        return ['added' => $added, 'updated' => $updated, 'structures' => $structures];
    }

    private function belongs(MapSolarsystem $mapSolarsystem, IntelStructure $structure): void
    {
        abort_unless($structure->map_id === $mapSolarsystem->map_id && $structure->solarsystem_id === $mapSolarsystem->solarsystem_id, 404);
    }

    private function structureIdFor(MapSolarsystem $mapSolarsystem, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return IntelStructure::query()->where('map_id', $mapSolarsystem->map_id)->where('solarsystem_id', $mapSolarsystem->solarsystem_id)->whereKey((int) $id)->value('id');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function log(MapSolarsystem $mapSolarsystem, string $kind, string $body, string $who, ?int $structureId = null, ?array $data = null, ?string $imagePath = null): IntelEntry
    {
        $entry = new IntelEntry([
            'map_id' => $mapSolarsystem->map_id,
            'solarsystem_id' => $mapSolarsystem->solarsystem_id,
            'intel_structure_id' => $structureId,
            'kind' => $kind,
            'body' => $body,
            'data' => $data,
            'image_path' => $imagePath,
            'author_name' => $who,
        ]);
        $entry->save();

        return $entry;
    }

    /** The human: the SeAT main, else the character, else the account (as notes are stamped). */
    private function who(User $user): string
    {
        $character = $user->active_character;

        return $character instanceof Character ? ($character->seat_main_name ?? $character->name) : $user->name;
    }

    private function label(IntelStructure $structure): string
    {
        $kind = $structure->kind === 'pos' ? 'POS' : ucfirst($structure->kind);

        return $structure->name ? sprintf('%s "%s"', $kind, $structure->name) : $kind;
    }

    /**
     * @return array<string, mixed>
     */
    private function structurePayload(IntelStructure $structure): array
    {
        return [
            'id' => $structure->id,
            'kind' => $structure->kind,
            'name' => $structure->name,
            'owner' => $structure->owner,
            'location' => $structure->location,
            'state' => $structure->state,
            'vulnerable_window' => $structure->vulnerable_window,
            'timer_at' => $structure->timer_at?->toIso8601String(),
            'fit' => $structure->fit,
            'note' => $structure->note,
            'seen_at' => $structure->seen_at->toIso8601String(),
            'created_by_name' => $structure->created_by_name,
            'updated_by_name' => $structure->updated_by_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entryPayload(MapSolarsystem $mapSolarsystem, IntelEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'body' => $entry->body,
            'data' => $entry->data,
            'intel_structure_id' => $entry->intel_structure_id,
            'author_name' => $entry->author_name,
            'created_at' => $entry->created_at->toIso8601String(),
            'image_url' => $entry->image_path ? route('map-solarsystems.intel.screenshot', [$mapSolarsystem, $entry]) : null,
        ];
    }
}
