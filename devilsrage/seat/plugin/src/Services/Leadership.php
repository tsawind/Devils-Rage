<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Services;

use Seat\Eveapi\Models\Corporation\CorporationRole;
use Seat\Eveapi\Models\Corporation\CorporationTitle;
use Seat\Web\Models\User;

/**
 * Who counts as leadership for the Devil's Rage pages:
 *  - "leader" (corp layout + FC planner): SeAT admin, the CEO, anyone with the in-game
 *    Director role or a title named "Director", or the SeAT permission devilsrage.planner.
 *  - "plan manager" (add/remove skill plans): leaders, a title named "Officer", or the
 *    SeAT permission devilsrage.skillplans.
 * CEO, role and titles only count in the main corporation (see Corporation), so being
 * CEO or director of a personal alt corp gives nothing here.
 * Patch 39: "View as member" (ViewMode) turns both off for the session.
 */
final class Leadership
{
    public function __construct(private readonly Corporation $corporation) {}

    /** @var array<int, array<string, bool>> */
    private array $cache = [];

    public function isLeader(?User $user): bool
    {
        // Patch 39: "View as member" drops leader rights on these pages.
        if ($user === null || ViewMode::asMember()) {
            return false;
        }

        return $this->isRealLeader($user);
    }

    /** A leader, whatever the view mode (who gets the "View as member" switch). */
    public function isRealLeader(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->remember($user, 'leader', fn (): bool => $user->isAdmin()
            || $this->hasPermission($user, 'devilsrage.planner')
            || $this->isCeo($user)
            || $this->hasDirectorRole($user)
            || $this->hasTitle($user, ['director']));
    }

    public function canManagePlans(?User $user): bool
    {
        if ($user === null || ViewMode::asMember()) {
            return false;
        }

        return $this->remember($user, 'plans', fn (): bool => $this->isLeader($user)
            || $this->hasPermission($user, 'devilsrage.skillplans')
            || $this->hasTitle($user, ['officer']));
    }

    private function isCeo(User $user): bool
    {
        $ceoId = $this->corporation->ceoId();

        return $ceoId !== null && in_array($ceoId, array_map('intval', $user->associatedCharacterIds()), true);
    }

    private function hasDirectorRole(User $user): bool
    {
        $corporationId = $this->corporation->mainId();

        return $corporationId !== null && CorporationRole::query()
            ->where('corporation_id', $corporationId)
            ->whereIn('character_id', $user->associatedCharacterIds())
            ->where('role', 'Director')
            ->where('type', 'roles')
            ->exists();
    }

    /**
     * A title of the main corporation held by any of the user's characters.
     *
     * @param  list<string>  $names  lower-case title names
     */
    private function hasTitle(User $user, array $names): bool
    {
        $corporationId = $this->corporation->mainId();
        if ($corporationId === null) {
            return false;
        }

        $titles = CorporationTitle::query()
            ->where('corporation_id', $corporationId)
            ->whereHas('characters', fn ($query) => $query->whereIn('character_infos.character_id', $user->associatedCharacterIds()))
            ->pluck('name');

        foreach ($titles as $title) {
            if (in_array(mb_strtolower(trim(strip_tags((string) $title))), $names, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }

    private function remember(User $user, string $key, \Closure $check): bool
    {
        return $this->cache[$user->id][$key] ??= (bool) $check();
    }
}
