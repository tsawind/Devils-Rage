// Read-only check: why does (or doesn't) SeAT hand out Discord roles to linked users?
// Run: docker compose exec front php artisan tinker --execute="$(cat ~/wormholesystems-containers/wormhole-systems/devilsrage/seat/diag-discord.php)"
$p = \Warlof\Seat\Connector\Models\User::where('connector_type','discord')->get();
foreach ($p as $u) {
  $s = $u->user;
  echo "SeAT user: {$s->name} | Discord: {$u->connector_name}\n";
  echo "  strict mode: " . (setting('seat-connector.strict', true) ? 'on' : 'off') . "\n";
  echo "  account active: " . ($s->active ? 'yes' : 'NO') . "\n";
  echo "  characters: " . $s->all_characters()->count() . " | valid tokens: " . $s->refresh_tokens->count() . "\n";
  $ok = $s->refresh_tokens->pluck('character_id')->all();
  foreach ($s->all_characters() as $c) { if (!in_array($c->character_id, $ok)) echo "  NO VALID TOKEN: {$c->name}\n"; }
  echo "  corporations: " . $s->characters->pluck('affiliation.corporation_id')->unique()->implode(', ') . "\n";
  echo "  roles SeAT would give: " . implode(', ', \Warlof\Seat\Connector\Models\Set::whereIn('connector_id', $u->allowedSets())->pluck('name')->all()) . "\n";
}
echo "Rules: " . \Warlof\Seat\Connector\Models\Set::where('connector_type','discord')->has('corporations')->pluck('name')->implode(', ') . " (corp)\n";
