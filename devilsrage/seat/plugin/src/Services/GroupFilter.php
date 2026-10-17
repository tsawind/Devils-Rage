<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use DevilsRage\Seat\Models\AccountCharacter;
use DevilsRage\Seat\Models\AccountSetting;
use DevilsRage\Seat\Models\LaunchGroup;
use Seat\Web\Models\User;

/**
 * Patch 39: the launch group filter on the FC planner and skill plans: Everyone, the corp
 * groups (Main / Second / Third), then "Your groups" (your private launch groups). Leaders
 * also get every pilot's private groups (pilots who keep their accounts private excepted).
 * Values: "" (everyone), "g:<id>"; a bare corp group name (old links) still works.
 */
final class GroupFilter
{
    /**
     * @return list<array{section: string, options: list<array{value: string, label: string}>}>
     */
    public function options(User $user, bool $leader): array
    {
        $groups = LaunchGroup::query()->orderBy('position')->orderBy('name')->get();
        $sections = [
            ['section' => 'Corp groups', 'options' => $this->map($groups->whereNull('user_id'))],
            ['section' => 'Your groups', 'options' => $this->map($groups->where('user_id', $user->id))],
        ];

        if ($leader) {
            $private = AccountSetting::query()->where('share', false)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
            $others = $groups->filter(fn (LaunchGroup $group): bool => $group->user_id !== null && $group->user_id !== $user->id && ! in_array($group->user_id, $private, true));
            $owners = User::query()->whereIn('id', $others->pluck('user_id')->unique()->all())->with('main_character')->get()->keyBy('id');
            $sections[] = [
                'section' => "Pilots' groups",
                'options' => $others
                    ->map(fn (LaunchGroup $group): array => [
                        'value' => 'g:'.$group->id,
                        'label' => ($owners->get($group->user_id)?->main_character?->name ?? $owners->get($group->user_id)?->name ?? 'Pilot').' · '.$group->name,
                    ])
                    ->sortBy('label')
                    ->values()
                    ->all(),
            ];
        }

        return array_values(array_filter($sections, fn (array $section): bool => $section['options'] !== []));
    }

    /**
     * The chosen group (null when Everyone or a group this viewer may not use).
     */
    public function resolve(?string $value, User $user, bool $leader): ?LaunchGroup
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $group = str_starts_with($value, 'g:')
            ? LaunchGroup::query()->find((int) substr($value, 2))
            : LaunchGroup::query()->whereNull('user_id')->where('name', $value)->first();

        if ($group === null || $group->user_id === null || $group->user_id === $user->id) {
            return $group;
        }

        return $leader && AccountSetting::sharesFor($group->user_id) ? $group : null;
    }

    /**
     * Characters in the group.
     *
     * @return list<int>
     */
    public function characterIds(LaunchGroup $group): array
    {
        return AccountCharacter::query()
            ->whereHas('launchGroups', fn ($query) => $query->where('devilsrage_launch_groups.id', $group->id))
            ->pluck('character_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  iterable<LaunchGroup>  $groups
     * @return list<array{value: string, label: string}>
     */
    private function map(iterable $groups): array
    {
        $options = [];
        foreach ($groups as $group) {
            $options[] = ['value' => 'g:'.$group->id, 'label' => $group->name];
        }

        return $options;
    }
}
