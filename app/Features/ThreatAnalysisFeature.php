<?php

declare(strict_types=1);

namespace App\Features;

use App\Enums\RemovableCard;
use App\Http\Resources\ThreatAnalysisResource;
use App\Models\MapSolarsystem;
use App\Models\WormholeSystem;
use App\Support\SystemIntelligence;
use Inertia\Inertia;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;

final readonly class ThreatAnalysisFeature implements ProvidesInertiaProperties
{
    /**
     * @param  string[]  $hiddenCards
     */
    public function __construct(
        private ?MapSolarsystem $selectedSolarsystem,
        private array $hiddenCards = [],
    ) {}

    public function toInertiaProperties(RenderContext $context): array
    {
        if (in_array(RemovableCard::ThreatAnalysis->value, $this->hiddenCards)) {
            return [];
        }

        return [
            'threat_analysis' => Inertia::defer($this->getThreatAnalysis(...)),
        ];
    }

    /**
     * Patch 58: the nightly threat level and top groups, plus who lives there, hunts there and
     * passed through (worked out now, without us), for wormhole and k-space systems alike.
     *
     * @return array<string, mixed>|null
     */
    private function getThreatAnalysis(): ?array
    {
        if (! $this->selectedSolarsystem instanceof MapSolarsystem) {
            return null;
        }

        $wormholeSystem = WormholeSystem::query()
            ->with('threats')
            ->where('id', $this->selectedSolarsystem->solarsystem_id)
            ->first();

        $base = $wormholeSystem instanceof WormholeSystem
            ? (new ThreatAnalysisResource($wormholeSystem))->resolve()
            : ['solarsystem_id' => $this->selectedSolarsystem->solarsystem_id, 'threat_level' => 'unknown', 'threat_data' => [], 'threat_analyzed_at' => null];

        return [
            ...$base,
            'map_solarsystem_id' => $this->selectedSolarsystem->id,
            'intelligence' => SystemIntelligence::for($this->selectedSolarsystem->solarsystem_id, $this->selectedSolarsystem->map),
        ];
    }
}
