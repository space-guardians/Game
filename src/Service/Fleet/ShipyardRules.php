<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Model\Economy\Resources;

/**
 * Formules du chantier spatial (§4.5), sans accès aux données : durée de construction d'un vaisseau, nombre de
 * postes (commandes construites en parallèle) et poste attribué à une nouvelle commande.
 */
final readonly class ShipyardRules
{
    private const float CONSTRUCTION_DIVISOR = 2500.0;

    /** Niveaux de chantier par poste supplémentaire : 1 poste au niveau 1, 2 au niveau 4, 3 au niveau 8… */
    public const int LEVELS_PER_SLOT = 4;

    /**
     * Durée de construction d'un vaisseau en secondes (au moins une) : (métal + cristal) / (2 500 × (1 + chantier)
     * × 2^nanites) heures, comme les bâtiments mais accélérée par le chantier au lieu de l'usine de robots.
     */
    public function unitSeconds(Resources $cost, int $shipyardLevel, int $naniteFactoryLevel, float $universeSpeed): int
    {
        $hours = ($cost->metal + $cost->crystal) / (self::CONSTRUCTION_DIVISOR * (1 + $shipyardLevel) * 2 ** $naniteFactoryLevel) / $universeSpeed;

        return max(1, (int) ceil($hours * 3600));
    }

    /** Postes du chantier : aucun sans chantier, puis 1 + un tous les LEVELS_PER_SLOT niveaux */
    public function slots(int $shipyardLevel): int
    {
        return $shipyardLevel <= 0 ? 0 : 1 + intdiv($shipyardLevel, self::LEVELS_PER_SLOT);
    }

    /**
     * Poste d'une nouvelle commande et heure de début : le poste qui se libère le plus tôt (un poste sans commande est
     * libre tout de suite), à égalité le plus petit numéro.
     *
     * @param array<int, \DateTimeImmutable> $busyUntil fin de la dernière commande de chaque poste occupé
     *
     * @return array{0: int, 1: \DateTimeImmutable} poste, début
     */
    public function nextSlot(int $slots, array $busyUntil, \DateTimeImmutable $now): array
    {
        if ($slots < 1) {
            throw new \InvalidArgumentException('Le chantier spatial n\'a aucun poste.');
        }

        $best = null;
        for ($slot = 0; $slot < $slots; ++$slot) {
            $free = max($now, $busyUntil[$slot] ?? $now);
            if (null === $best || $free < $best[1]) {
                $best = [$slot, $free];
            }
        }

        return $best;
    }
}
