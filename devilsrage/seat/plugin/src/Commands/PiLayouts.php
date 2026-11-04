<?php

declare(strict_types=1);

namespace DevilsRage\Seat\Commands;

use DevilsRage\Seat\Models\PiScanSnapshot;
use DevilsRage\Seat\Models\PiTemplate as StoredTemplate;
use DevilsRage\Seat\Pi\PiLayout;
use DevilsRage\Seat\Pi\PiScan;
use DevilsRage\Seat\Pi\PiTemplate;
use DevilsRage\Seat\Services\JitaPrices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P0 → P1 layouts for a character's planets: for each planet, every raw it has a scan of is
 * scored (Jita sell of its P1 × the best free hotspot's heat, keeping clear of corp mates'
 * heads on the same raw), and the best one is drawn as an EVE template (PiLayout) and, with
 * --save, put in the character's owner's Templates on the PI screen.
 */
class PiLayouts extends Command
{
    protected $signature = 'devilsrage:pi:layouts {character : Character name} {--planet=* : Planet names (default: the character\'s colonies)} {--raw=* : Only these raws} {--save : Add the templates to the owner\'s Templates}';

    protected $description = 'Draw P0 to P1 colony templates on scanned hotspots';

    public function handle(JitaPrices $prices): int
    {
        $character = DB::table('character_infos')->where('name', $this->argument('character'))->first(['character_id', 'name']);
        if ($character === null) {
            $this->error('No such character on SeAT.');

            return self::FAILURE;
        }
        $names = $this->option('planet');
        $planets = $names !== []
            ? DB::table('mapDenormalize')->whereIn('itemName', $names)->where('groupID', 7)->get(['itemID', 'itemName', 'typeID', 'radius'])
            : DB::table('mapDenormalize')->whereIn('itemID', DB::table('character_planets')->where('character_id', $character->character_id)->pluck('planet_id'))->get(['itemID', 'itemName', 'typeID', 'radius']);
        $owner = (int) DB::table('refresh_tokens')->where('character_id', $character->character_id)->value('user_id');
        foreach ($planets as $planet) {
            $this->line('');
            $this->line("== {$planet->itemName}");
            $kind = trim((string) preg_replace('/^Planet \((.*)\)$/', '$1', (string) DB::table('invTypes')->where('typeID', $planet->typeID)->value('typeName')));
            $types = [];
            foreach (['ecu' => "$kind Extractor Control Unit", 'launchpad' => "$kind Launchpad", 'storage' => "$kind Storage Facility", 'basic' => "$kind Basic Industry Facility"] as $key => $name) {
                $types[$key] = (int) DB::table('invTypes')->where('typeName', $name)->value('typeID');
            }
            if (in_array(0, $types, true)) {
                $this->warn("  No $kind buildings in the SDE.");

                continue;
            }
            $radiusKm = (float) $planet->radius / 1000;
            $level = (int) (DB::table('character_planets')->where('character_id', $character->character_id)->where('planet_id', $planet->itemID)->value('upgrade_level') ?? 5);
            $raws = DB::table('invTypes')->where('typeName', 'like', "$kind % Extractor")->pluck('typeName')
                ->map(fn ($n) => trim(substr((string) $n, strlen($kind) + 1, -strlen(' Extractor'))))->unique()->values();
            $choices = [];
            foreach ($raws as $raw) {
                if ($this->option('raw') !== [] && ! in_array($raw, $this->option('raw'), true)) {
                    continue;
                }
                $p0 = (int) DB::table('invTypes')->where('typeName', $raw)->value('typeID');
                $schematic = DB::table('planetSchematicsTypeMap')->where('typeID', $p0)->where('isInput', 1)->value('schematicID');
                $p1 = (int) DB::table('planetSchematicsTypeMap')->where('schematicID', $schematic)->where('isInput', 0)->value('typeID');
                $scan = PiScanSnapshot::query()->where('planet_id', $planet->itemID)->where('resource', $raw)->orderByDesc('created_at')->first();
                $pulling = DB::table('character_planet_extractors')->where('planet_id', $planet->itemID)->where('product_type_id', $p0)->where('character_id', '!=', $character->character_id)->get(['character_id', 'pin_id']);
                $avoid = [];
                foreach ($pulling as $x) {
                    foreach (DB::table('character_planet_heads')->where('character_id', $x->character_id)->where('extractor_id', $x->pin_id)->get(['latitude', 'longitude']) as $h) {
                        $avoid[] = [(float) $h->latitude, (float) $h->longitude];
                    }
                }
                if ($scan === null) {
                    $this->line(sprintf('  %-18s no scan yet (%d corp extractors on it)', $raw, $pulling->count()));

                    continue;
                }
                $spot = PiLayout::hotspot(PiScan::despeckle($scan->cells), $radiusKm, $avoid);
                if ($spot === null) {
                    $this->line(sprintf('  %-18s no free ground (%d corp extractors on it)', $raw, $pulling->count()));

                    continue;
                }
                $choices[] = compact('raw', 'p0', 'p1', 'spot', 'avoid') + ['scanned' => (string) $scan->created_at, 'others' => $pulling->count()];
            }
            if ($choices === []) {
                continue;
            }
            $priced = $prices->get(array_column($choices, 'p1'));
            foreach ($choices as &$c) {
                $c['price'] = (float) ($priced[$c['p1']]['sell'] ?? 0);
                $c['score'] = $c['price'] * $c['spot']['heat'];
                $this->line(sprintf('  %-18s → %-20s hotspot heat %.2f, P1 %s ISK, %d corp extractors elsewhere on it, scanned %s', $c['raw'], DB::table('invTypes')->where('typeID', $c['p1'])->value('typeName'), $c['spot']['heat'], number_format($c['price'], 0), $c['others'], $c['scanned']));
            }
            unset($c);
            usort($choices, fn ($a, $b) => $b['score'] <=> $a['score']);
            $pick = $choices[0];
            $p1Name = (string) DB::table('invTypes')->where('typeID', $pick['p1'])->value('typeName');
            $layout = PiLayout::p1([
                'planet_type_id' => (int) $planet->typeID, 'radius_km' => $radiusKm, 'hotspot' => [$pick['spot']['lat'], $pick['spot']['lon']],
                'toward' => $pick['spot']['toward'], 'types' => $types, 'p0' => $pick['p0'], 'p1' => $pick['p1'], 'cc_level' => max(1, min(5, $level ?: 5)),
                'comment' => "DR P1 {$planet->itemName} {$p1Name}",
            ]);
            $this->info(sprintf('  Pick: %s → %s, %d basic factories (CC %d: %.0f MW, %.0f tf), extractor at %.4f, %.4f', $pick['raw'], $p1Name, $layout['factories'], $level ?: 5, $layout['power'], $layout['cpu'], $pick['spot']['lat'], $pick['spot']['lon']));
            $body = json_encode($layout['template'], JSON_THROW_ON_ERROR);
            $this->line('  '.$body);
            if ($this->option('save') && $owner > 0) {
                $parsed = PiTemplate::parse($body);
                StoredTemplate::query()->updateOrCreate(
                    ['user_id' => $owner, 'fingerprint' => PiTemplate::fingerprint($parsed)],
                    [
                        'name' => mb_substr("P1 {$planet->itemName} · {$p1Name} · {$layout['factories']} factories", 0, 80),
                        'note' => mb_substr(sprintf('Drawn on the %s scan of %s. Extractor on the hotspot (heat %.2f), heads spread round it when the program starts: 1 d 45 m program (15-minute cycles), restart daily. Needs a command center %d.', $pick['raw'], $pick['scanned'], $pick['spot']['heat'], $level ?: 5), 0, 500),
                        'body' => $body, 'planet_type_id' => (int) $planet->typeID, 'shared' => false,
                    ],
                );
                $this->info('  Saved to Templates.');
            }
        }

        return self::SUCCESS;
    }
}
