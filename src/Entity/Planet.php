<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Planète d'un système stellaire. Sa position est locale au système (orbite + angle),
 * jamais une coordonnée globale.
 *
 * @see §2.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: PlanetRepository::class)]
#[ORM\UniqueConstraint(name: 'planet_system_orbit_unique', fields: ['system', 'position.orbit'])]
final class Planet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Empire propriétaire ; libre tant que null */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Empire $owner = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'planets')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private StarSystem $system,
        #[ORM\Embedded(columnPrefix: false)]
        private OrbitalPosition $position,
        /** Température moyenne en °C, fixée à la création : influence l'énergie solaire et le deutérium */
        #[ORM\Column]
        private int $temperature,
    ) {
        $system->addPlanet($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSystem(): StarSystem
    {
        return $this->system;
    }

    public function getPosition(): OrbitalPosition
    {
        return $this->position;
    }

    public function getTemperature(): int
    {
        return $this->temperature;
    }

    public function getOwner(): ?Empire
    {
        return $this->owner;
    }

    public function assignTo(Empire $empire): void
    {
        if (null !== $this->owner && $this->owner !== $empire) {
            throw new \LogicException(\sprintf('La planète %s appartient déjà à l\'empire « %s ».', $this, $this->owner->getName()));
        }
        $this->owner = $empire;
    }

    /** Adresse entre crochets, comme partout dans l'interface (charte §3) */
    public function __toString(): string
    {
        return '[' . $this->getAddress() . ']';
    }

    public function getAddress(): PlanetAddress
    {
        return new PlanetAddress(
            $this->system->getGalaxy()->getNumber(),
            $this->system->getNumber(),
            $this->position->orbit,
        );
    }
}
