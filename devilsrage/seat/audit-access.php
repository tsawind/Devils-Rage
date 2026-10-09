// Read-only audit: who can see other people's characters in SeAT, and why.
// Run: docker compose exec front php artisan tinker --execute="$(cat ~/wormholesystems-containers/wormhole-systems/devilsrage/seat/audit-access.php)"
//
// SeAT shows another pilot's character (assets, wallet, mail...) when ANY of these is true
// (vendor/eveseat/web/src/Acl/Policies/CharacterPolicy.php):
//   A. the viewer is a SeAT admin (superuser)
//   B. a role gives the viewer that character.* permission with NO filter (= every character)
//      or with a filter that matches the character's corporation/alliance
//   C. one of the viewer's characters is CEO of the character's corporation
//   D. one of the viewer's characters has the in-game Director role in that corporation
//      (also when that role comes from a title)

echo PHP_EOL . "== A. SeAT admins (see everything) ==" . PHP_EOL;
foreach (\Seat\Web\Models\User::where('admin', true)->get() as $u) {
    echo "  {$u->name}" . PHP_EOL;
}

echo PHP_EOL . "== B. Roles and what they open ==" . PHP_EOL;
foreach (\Seat\Web\Models\Acl\Role::with(['permissions', 'users', 'squads'])->get() as $role) {
    echo "Role \"{$role->title}\" | members: " . $role->users->pluck('name')->implode(', ')
        . " | via squads: " . ($role->squads->pluck('name')->implode(', ') ?: '-') . PHP_EOL;
    foreach ($role->permissions as $p) {
        $scope = $p->isCharacterScope() ? 'character' : ($p->isCorporationScope() ? 'corporation' : 'global');
        if ($scope === 'global') {
            $who = 'n/a';
        } elseif (! $p->hasFilters()) {
            $who = '!! EVERY ' . strtoupper($scope) . ' IN SEAT';
        } else {
            $who = 'only: ' . $p->pivot->filters;
        }
        echo "    " . ($p->pivot->not ? '[DENY] ' : '') . str_pad($p->title, 40) . " {$who}" . PHP_EOL;
    }
}

echo PHP_EOL . "== C/D. CEO or in-game Director (see their whole corporation) ==" . PHP_EOL;
$owner = [];
foreach (\Seat\Web\Models\User::standard()->get() as $u) {
    foreach ($u->associatedCharacterIds() as $cid) {
        $owner[$cid] = $u->name;
    }
}
foreach (\Seat\Eveapi\Models\Corporation\CorporationInfo::all() as $corp) {
    if (isset($owner[$corp->ceo_id])) {
        echo "  CEO of {$corp->name}: {$owner[$corp->ceo_id]}" . PHP_EOL;
    }
}
$directors = \Seat\Eveapi\Models\Corporation\CorporationRole::where('role', 'Director')->where('type', 'roles')->get();
foreach ($directors as $r) {
    $corp = optional(\Seat\Eveapi\Models\Corporation\CorporationInfo::find($r->corporation_id))->name ?? $r->corporation_id;
    $char = optional(\Seat\Eveapi\Models\Character\CharacterInfo::find($r->character_id))->name ?? $r->character_id;
    $who = $owner[$r->character_id] ?? '(not in SeAT)';
    echo "  Director in {$corp}: {$char} -> SeAT user {$who}" . PHP_EOL;
}
echo PHP_EOL;
