<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StarSystemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Système stellaire (« System » dans le cahier des charges ; renommé pour ne pas le confondre avec
 * les messages système ni avec le mot-clé SQL). Seuls les systèmes ont une position globale dans la galaxie.
 *
 * @see §2.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: StarSystemRepository::class)]
#[ORM\UniqueConstraint(name: 'star_system_galaxy_number_unique', fields: ['galaxy', 'number'])]
final class StarSystem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, Planet> */
    #[ORM\OneToMany(targetEntity: Planet::class, mappedBy: 'system', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['position.orbit' => 'ASC'])]
    private Collection $planets;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'systems')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Galaxy $galaxy,
        /** Numéro du système dans l'adresse logique (« Système 342 »), unique dans sa galaxie */
        #[ORM\Column]
        private int $number,
        #[ORM\Embedded(columnPrefix: false)]
        private GlobalPosition $position,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('Le numéro de système doit être strictement positif.');
        }

        $this->planets = new ArrayCollection();
        $galaxy->addSystem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGalaxy(): Galaxy
    {
        return $this->galaxy;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getPosition(): GlobalPosition
    {
        return $this->position;
    }

    public function getDistanceFromCenter(): float
    {
        return $this->position->distanceFromCenter();
    }

    /** Compté en base sans charger les planètes (collection EXTRA_LAZY) */
    public function getPlanetCount(): int
    {
        return $this->planets->count();
    }

    /** @return Collection<int, Planet> */
    public function getPlanets(): Collection
    {
        return $this->planets;
    }

    public function __toString(): string
    {
        return \sprintf('Système %d (galaxie %d)', $this->number, $this->galaxy->getNumber());
    }

    /** @internal Appelée par le constructeur de Planet pour garder les deux côtés de la relation synchronisés */
    public function addPlanet(Planet $planet): void
    {
        if (!$this->planets->contains($planet)) {
            $this->planets->add($planet);
        }
    }
}
