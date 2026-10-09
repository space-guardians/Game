<?php

declare(strict_types=1);

namespace App\Model\Combat;

use App\Entity\Fleet;
use App\Enum\Fleet\FleetStatus;

/**
 * Mouvement d'un camp au moment de l'engagement (§4.6.2, §4.7) : immobile (stationné à une planète, un système ou
 * n'importe quelle position, ou immobilisé en panne), ou en mouvement selon un vecteur d'approche (repère global).
 */
final readonly class SideMotion
{
    public function __construct(
        public bool $stationary,
        /** Vecteur d'approche (dx, dy) d'un camp en mouvement ; sert à l'angle d'attaque entre flottes convergentes */
        public ?float $headingX = null,
        public ?float $headingY = null,
    ) {}

    public static function stationary(): self
    {
        return new self(true);
    }

    public static function moving(float $headingX, float $headingY): self
    {
        return new self(false, $headingX, $headingY);
    }

    /**
     * Mouvement d'un camp d'après ses flottes : il suffit qu'une flotte du camp soit à l'arrêt (stationnée ou en panne)
     * pour que le camp tienne sa position et fasse face.
     *
     * @param list<Fleet> $fleets
     */
    public static function ofFleets(array $fleets): self
    {
        foreach ($fleets as $fleet) {
            if (FleetStatus::InFlight !== $fleet->getStatus()) {
                return self::stationary();
            }
        }

        return new self(false);
    }
}
