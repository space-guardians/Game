<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Fleet\SpacePosition;
use Doctrine\ORM\Mapping as ORM;

/**
 * Forme enregistrée d'une SpacePosition (position d'une flotte, destination d'un ordre) : identifiants et
 * coordonnées, sans clé étrangère — une position survit à la planète ou au système qu'elle désignait.
 */
#[ORM\Embeddable]
final readonly class SpaceLocation
{
    public function __construct(
        #[ORM\Column]
        public int $galaxyId,
        #[ORM\Column(nullable: true)]
        public ?int $systemId,
        #[ORM\Column]
        public float $anchorX,
        #[ORM\Column]
        public float $anchorY,
        #[ORM\Column(nullable: true)]
        public ?float $localX = null,
        #[ORM\Column(nullable: true)]
        public ?float $localY = null,
        #[ORM\Column(nullable: true)]
        public ?int $planetId = null,
    ) {}

    public static function of(SpacePosition $position): self
    {
        return new self(
            $position->galaxyId,
            $position->systemId,
            $position->anchor->x,
            $position->anchor->y,
            $position->localX,
            $position->localY,
            $position->planetId,
        );
    }

    public function toPosition(): SpacePosition
    {
        return SpacePosition::of($this->galaxyId, $this->systemId, new GlobalPosition($this->anchorX, $this->anchorY), $this->localX, $this->localY, $this->planetId);
    }
}
