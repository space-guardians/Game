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
     * pour que le camp tienne sa position et fasse face. Sinon, son cap est la moyenne des caps (normés) de ses
     * flottes en vol.
     *
     * @param list<Fleet>                $fleets
     * @param array<int, array{float, float}> $headings cap de chaque flotte en vol, par identifiant de flotte
     */
    public static function ofFleets(array $fleets, array $headings = []): self
    {
        $x = 0.0;
        $y = 0.0;
        foreach ($fleets as $fleet) {
            if (FleetStatus::InFlight !== $fleet->getStatus()) {
                return self::stationary();
            }
            [$dx, $dy] = $headings[(int) $fleet->getId()] ?? [0.0, 0.0];
            $norm = hypot($dx, $dy);
            if ($norm > 0) {
                $x += $dx / $norm;
                $y += $dy / $norm;
            }
        }

        return hypot($x, $y) > 1e-9 ? self::moving($x, $y) : new self(false);
    }
}
