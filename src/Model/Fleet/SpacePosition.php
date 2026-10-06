<?php

declare(strict_types=1);

namespace App\Model\Fleet;

use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;

/**
 * Position que peut viser (ou occuper) une flotte (§4.6.1) : une planète, un système entier, un point dans un système
 * sans planète visée, ou un point de l'espace hors de tout système. Toutes les positions d'une galaxie se ramènent au
 * même repère global : celui des systèmes, la position locale d'un point dans un système s'ajoutant à son centre.
 */
final readonly class SpacePosition
{
    private function __construct(
        public int $galaxyId,
        /** Système concerné ; null dans l'espace hors système */
        public ?int $systemId,
        /** Centre du système, ou point de l'espace hors système */
        public GlobalPosition $anchor,
        /** Point local au système (relatif à l'étoile) ; null pour le système entier ou hors système */
        public ?float $localX = null,
        public ?float $localY = null,
        public ?int $planetId = null,
    ) {}

    public static function planet(Planet $planet): self
    {
        $system = $planet->getSystem();
        ['x' => $x, 'y' => $y] = $planet->getPosition()->toLocalCartesian();

        return new self((int) $system->getGalaxy()->getId(), (int) $system->getId(), $system->getPosition(), $x, $y, (int) $planet->getId());
    }

    /** Le système entier : la flotte s'y stationne au niveau du système (§4.6.2) */
    public static function system(StarSystem $system): self
    {
        return new self((int) $system->getGalaxy()->getId(), (int) $system->getId(), $system->getPosition());
    }

    /** Un point précis d'un système, sans viser de planète */
    public static function inSystem(StarSystem $system, float $localX, float $localY): self
    {
        return new self((int) $system->getGalaxy()->getId(), (int) $system->getId(), $system->getPosition(), $localX, $localY);
    }

    /** Un point de l'espace hors de tout système */
    public static function deepSpace(int $galaxyId, GlobalPosition $position): self
    {
        return new self($galaxyId, null, $position);
    }

    /** Position construite à partir de valeurs brutes (tests, positions déjà connues) */
    public static function of(int $galaxyId, ?int $systemId, GlobalPosition $anchor, ?float $localX = null, ?float $localY = null, ?int $planetId = null): self
    {
        if ((null === $localX) !== (null === $localY)) {
            throw new \InvalidArgumentException('Un point local a deux coordonnées.');
        }
        if (null === $systemId && null !== $localX) {
            throw new \InvalidArgumentException('Hors système, une position n\'a pas de coordonnées locales.');
        }

        return new self($galaxyId, $systemId, $anchor, $localX, $localY, $planetId);
    }

    public function isInSystem(): bool
    {
        return null !== $this->systemId;
    }

    /** Point précis dans un système (planète ou coordonnée), par opposition au système entier */
    public function hasLocalPoint(): bool
    {
        return null !== $this->localX;
    }

    public function isSameSystem(self $other): bool
    {
        return null !== $this->systemId && $this->galaxyId === $other->galaxyId && $this->systemId === $other->systemId;
    }

    /** Point dans le repère global de la galaxie */
    public function global(): GlobalPosition
    {
        return null === $this->localX
            ? $this->anchor
            : new GlobalPosition($this->anchor->x + $this->localX, $this->anchor->y + (float) $this->localY);
    }
}
