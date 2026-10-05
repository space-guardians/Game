<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Enum\Account\StartingOrientation;
use App\Model\Universe\PlanetCandidate;
use Random\Randomizer;

/**
 * Choisit la planète mère d'un nouvel empire parmi les planètes libres d'une galaxie (§2.4) :
 * - agressif : dans la zone centrale (systèmes les plus proches du centre), là où les joueurs se concentrent ;
 * - producteur : en périphérie (systèmes les plus éloignés), zone peu peuplée.
 * Dans sa zone, on privilégie les systèmes les moins occupés, pour ne pas entasser les débutants, puis on tire au
 * hasard. Quand une zone est pleine, elle s'élargit d'elle-même : les quantiles portent sur les planètes encore libres.
 */
final readonly class StartingPlanetSelector
{
    /** Part des systèmes (par distance au centre) qui forme la zone de chaque orientation */
    public const float ZONE_SHARE = 0.35;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * @param list<PlanetCandidate> $candidates
     */
    public function select(array $candidates, StartingOrientation $orientation): ?PlanetCandidate
    {
        if ([] === $candidates) {
            return null;
        }

        $zone = $this->zone($candidates, $orientation);
        $leastOccupied = min(array_map(static fn(PlanetCandidate $candidate): int => $candidate->occupiedInSystem, $zone));
        $best = array_values(array_filter($zone, static fn(PlanetCandidate $candidate): bool => $candidate->occupiedInSystem === $leastOccupied));

        return $best[$this->randomizer->getInt(0, \count($best) - 1)];
    }

    /**
     * Candidats dont le système est parmi les ZONE_SHARE plus proches (agressif) ou plus éloignés (producteur).
     *
     * @param list<PlanetCandidate> $candidates
     *
     * @return non-empty-list<PlanetCandidate>
     */
    private function zone(array $candidates, StartingOrientation $orientation): array
    {
        $distances = [];
        foreach ($candidates as $candidate) {
            $distances[$candidate->systemId] = $candidate->distance;
        }
        sort($distances);
        $index = (int) floor((\count($distances) - 1) * self::ZONE_SHARE);

        $zone = StartingOrientation::Aggressive === $orientation
            ? array_filter($candidates, static fn(PlanetCandidate $candidate): bool => $candidate->distance <= $distances[$index])
            : array_filter($candidates, static fn(PlanetCandidate $candidate): bool => $candidate->distance >= $distances[\count($distances) - 1 - $index]);
        \assert([] !== $zone);

        return array_values($zone);
    }
}
