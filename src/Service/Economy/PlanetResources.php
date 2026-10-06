<?php

declare(strict_types=1);

namespace App\Service\Economy;

use App\Entity\Planet;
use App\Model\Economy\ResourceSnapshot;
use Psr\Clock\ClockInterface;

/**
 * Ressources d'une planète calculées à la demande (§4.2) : pas de tâche par planète, le stock enregistré est
 * complété par la production écoulée depuis sa dernière consolidation.
 *
 * - snapshot() : lecture, sans écriture (affichage) ;
 * - settle() : consolide le stock dans la planète, avant toute action qui dépense ou ajoute des ressources
 *   (construction, pillage…) ; l'appelant enregistre (flush).
 */
final readonly class PlanetResources
{
    public function __construct(
        private PlanetEconomy $economy,
        private ResourceAccumulator $accumulator,
        private ClockInterface $clock,
    ) {}

    public function snapshot(Planet $planet): ResourceSnapshot
    {
        $now = $this->now();
        $output = $this->economy->output($planet);
        $updatedAt = $planet->getResourcesUpdatedAt();
        $hours = null === $updatedAt ? 0.0 : ($now->getTimestamp() - $updatedAt->getTimestamp()) / 3600;

        return new ResourceSnapshot(
            $this->accumulator->accumulate($planet->getResources(), $output->hourlyProduction, $output->capacity, $hours),
            $output,
            $now,
        );
    }

    public function settle(Planet $planet): ResourceSnapshot
    {
        $snapshot = $this->snapshot($planet);
        $planet->storeResources($snapshot->amounts, $snapshot->at);

        return $snapshot;
    }

    /** À la seconde, comme les dates enregistrées : aucune fraction de seconde produite deux fois */
    private function now(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }
}
