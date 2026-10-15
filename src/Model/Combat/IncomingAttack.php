<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Entity\Empire;
use App\Entity\Planet;

/**
 * Attaque entrante détectée (§4.12) : bataille programmée sur une planète de l'empire par un empire non allié, avec son
 * heure d'arrivée.
 */
final readonly class IncomingAttack
{
    public function __construct(
        public int $battleId,
        public Planet $planet,
        public Empire $attacker,
        public \DateTimeImmutable $detectedAt,
        public \DateTimeImmutable $arrivesAt,
    ) {}
}
