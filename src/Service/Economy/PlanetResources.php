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

    /**
     * @param \DateTimeImmutable|null $at instant du calcul, maintenant par défaut ; un instant passé sert à appliquer
     *                                   un changement de production à l'heure exacte où il s'est produit (fin de
     *                                   construction résolue avec quelques secondes de retard)
     */
    public function snapshot(Planet $planet, ?\DateTimeImmutable $at = null): ResourceSnapshot
    {
        $now = self::toSecond($at ?? $this->clock->now());
        $output = $this->economy->output($planet);
        $updatedAt = $planet->getResourcesUpdatedAt();
        if (null !== $updatedAt && $now < $updatedAt) {
            // Déjà consolidé plus tard (autre action entre-temps) : ne pas faire reculer la date, rien à produire
            $now = $updatedAt;
        }
        $hours = null === $updatedAt ? 0.0 : ($now->getTimestamp() - $updatedAt->getTimestamp()) / 3600;

        return new ResourceSnapshot(
            $this->accumulator->accumulate($planet->getResources(), $output->hourlyProduction, $output->capacity, $hours),
            $output,
            $now,
        );
    }

    public function settle(Planet $planet, ?\DateTimeImmutable $at = null): ResourceSnapshot
    {
        $snapshot = $this->snapshot($planet, $at);
        $planet->storeResources($snapshot->amounts, $snapshot->at);

        return $snapshot;
    }

    /** À la seconde, comme les dates enregistrées : aucune fraction de seconde produite deux fois */
    private static function toSecond(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));
    }
}
