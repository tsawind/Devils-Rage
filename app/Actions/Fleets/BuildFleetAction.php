<?php

declare(strict_types=1);

namespace App\Actions\Fleets;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\FleetLayout;
use App\Models\User;
use NicolasKion\Esi\DTO\EsiResult;
use NicolasKion\Esi\DTO\FleetInfo;
use NicolasKion\Esi\DTO\FleetMember;
use NicolasKion\Esi\DTO\FleetWing;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 67: build a saved layout in the fleet you're boss of, through EVE's fleet API: make
 * the wings and squads it needs, name them, then invite every character to its own place (wing
 * commander, squad commander or squad member), or move it there if it's already in the fleet.
 * Each character still says yes to EVE's invite in their own client.
 *
 * Extra wings and squads already in the fleet are left alone; the boss stays where they are.
 */
final readonly class BuildFleetAction
{
    public function __construct(private Esi $esi) {}

    /**
     * @return array{ok: bool, message: string, boss?: string, fleet_id?: int, results?: list<array{id: int, name: string, place: string, outcome: string, detail: string|null}>}
     */
    public function handle(User $user, FleetLayout $layout): array
    {
        [$boss, $info] = $this->bossAndFleet($user);
        if (! $boss instanceof Character || ! $info instanceof FleetInfo) {
            return ['ok' => false, 'message' => 'Start a fleet first with one of your characters as fleet boss (it needs the Write fleet permission: Settings → EVE characters → Grant all permissions), then try again.'];
        }

        $wings = $this->wings($boss, $info->fleet_id);
        if ($wings === null) {
            return ['ok' => false, 'message' => 'EVE did not answer for the fleet\'s wings. Try again in a moment.'];
        }

        // Wings and squads first, named as in the layout.
        $plan = $layout->layout['wings'] ?? [];
        $wingIds = [];
        $squadIds = [];
        foreach ($plan as $w => $wing) {
            $existing = $wings[$w] ?? null;
            $wingId = $existing->id ?? $this->created($this->esi->createFleetWing($boss, $info->fleet_id));
            if ($wingId === null) {
                return ['ok' => false, 'message' => sprintf('EVE wouldn\'t make wing %d. Fleets hold up to 25 wings.', $w + 1)];
            }
            if ($existing?->name !== $wing['name']) {
                $this->esi->renameFleetWing($boss, $info->fleet_id, $wingId, mb_substr((string) $wing['name'], 0, 10));
            }
            $wingIds[$w] = $wingId;
        }
        // A new wing comes with a squad of its own: read them again before adding squads.
        $wings = $this->wings($boss, $info->fleet_id) ?? $wings;
        $byId = [];
        foreach ($wings as $wing) {
            $byId[$wing->id] = $wing;
        }
        foreach ($plan as $w => $wing) {
            $squads = array_values($byId[$wingIds[$w]]->squads ?? []);
            foreach ($wing['squads'] as $s => $squad) {
                $existing = $squads[$s] ?? null;
                $squadId = $existing->id ?? $this->created($this->esi->createFleetSquad($boss, $info->fleet_id, $wingIds[$w]));
                if ($squadId === null) {
                    return ['ok' => false, 'message' => sprintf('EVE wouldn\'t make squad %d in wing %d. Wings hold up to 25 squads.', $s + 1, $w + 1)];
                }
                if ($existing?->name !== $squad['name']) {
                    $this->esi->renameFleetSquad($boss, $info->fleet_id, $squadId, mb_substr((string) $squad['name'], 0, 10));
                }
                $squadIds[$w][$s] = $squadId;
            }
        }

        // Who's in the fleet already, and where.
        $members = [];
        try {
            $result = $this->esi->getFleetMembers($boss, $info->fleet_id);
            if ($result->wasSuccessful()) {
                foreach ($result->data as $member) {
                    $members[$member->character_id] = $member;
                }
            }
        } catch (Throwable) {
            // Treat everyone as new: an invite to someone already in the fleet just fails.
        }

        $results = [];
        $place = function (array $person, string $role, int $wingId, ?int $squadId, string $label) use ($boss, $info, $members, &$results): void {
            $id = (int) $person['id'];
            $name = (string) $person['name'];
            if ($id === $boss->id) {
                $results[] = ['id' => $id, 'name' => $name, 'place' => $label, 'outcome' => 'boss', 'detail' => 'You: the fleet boss stays where you are.'];

                return;
            }
            $current = $members[$id] ?? null;
            if ($current instanceof FleetMember && $current->role === $role && $current->wing_id === $wingId && ($squadId === null || $current->squad_id === $squadId)) {
                $results[] = ['id' => $id, 'name' => $name, 'place' => $label, 'outcome' => 'already', 'detail' => null];

                return;
            }
            try {
                $sent = $current instanceof FleetMember
                    ? $this->esi->moveFleetMember($boss, $info->fleet_id, $id, $role, $squadId, $wingId)
                    : $this->esi->inviteFleetMember($boss, $info->fleet_id, $id, $role, $squadId, $wingId);
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'name' => $name, 'place' => $label, 'outcome' => 'failed', 'detail' => $e->getMessage()];

                return;
            }
            $results[] = [
                'id' => $id,
                'name' => $name,
                'place' => $label,
                'outcome' => $sent->wasSuccessful() ? ($current instanceof FleetMember ? 'moved' : 'invited') : 'failed',
                'detail' => $sent->wasSuccessful() ? null : self::why($sent),
            ];
        };

        foreach ($plan as $w => $wing) {
            if (isset($wing['commander']['id'])) {
                $place($wing['commander'], 'wing_commander', $wingIds[$w], null, $wing['name'].' commander');
            }
            foreach ($wing['squads'] as $s => $squad) {
                if (isset($squad['commander']['id'])) {
                    $place($squad['commander'], 'squad_commander', $wingIds[$w], $squadIds[$w][$s], $wing['name'].' · '.$squad['name'].' commander');
                }
                foreach ($squad['members'] as $member) {
                    $place($member, 'squad_member', $wingIds[$w], $squadIds[$w][$s], $wing['name'].' · '.$squad['name']);
                }
            }
        }

        $invited = count(array_filter($results, fn (array $r): bool => in_array($r['outcome'], ['invited', 'moved'], true)));

        return [
            'ok' => true,
            'message' => sprintf('Fleet built: %d invited or moved, %d already in place.', $invited, count(array_filter($results, fn (array $r): bool => $r['outcome'] === 'already'))),
            'boss' => $boss->name,
            'fleet_id' => $info->fleet_id,
            'results' => $results,
        ];
    }

    /**
     * Your character that is boss of the fleet it's in (and may change it).
     *
     * @return array{0: Character|null, 1: FleetInfo|null}
     */
    private function bossAndFleet(User $user): array
    {
        $characters = $user->characters()->get()->sortByDesc(fn (Character $character): bool => $character->id === $user->active_character?->id);
        foreach ($characters as $character) {
            if (! $character->getEsiTokenWithScope(EsiScope::WriteFleet) instanceof EsiToken || ! $character->getEsiTokenWithScope(EsiScope::ReadFleet) instanceof EsiToken) {
                continue;
            }
            try {
                $result = $this->esi->getCharacterFleet($character);
            } catch (Throwable) {
                continue;
            }
            if ($result->wasSuccessful() && $result->data->fleet_boss_id === $character->id) {
                return [$character, $result->data];
            }
        }

        return [null, null];
    }

    /** @return list<FleetWing>|null */
    private function wings(Character $boss, int $fleetId): ?array
    {
        try {
            $result = $this->esi->getFleetWings($boss, $fleetId);
        } catch (Throwable) {
            return null;
        }
        if ($result->failed()) {
            return null;
        }

        return array_values($result->data);
    }

    private function created(EsiResult $result): ?int
    {
        return $result->wasSuccessful() && is_int($result->data) && $result->data > 0 ? $result->data : null;
    }

    /** EVE's reason in plain words (e.g. the character is offline or already in a fleet). */
    public static function why(EsiResult $result): string
    {
        $body = (string) ($result->error->body ?? '');
        $decoded = json_decode($body, true);
        $text = is_array($decoded) ? (string) ($decoded['error'] ?? $body) : $body;

        return match (true) {
            str_contains(mb_strtolower($text), 'online') => 'Not logged in right now.',
            str_contains(mb_strtolower($text), 'already') => 'Already in a fleet (leave it first).',
            $text !== '' => $text,
            default => sprintf('EVE said no (%d).', $result->error->code ?? 0),
        };
    }
}
