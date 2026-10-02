<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GalaxyRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Ignore;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Galaxie de l'univers, centrée sur (0 ; 0). Sa taille n'est pas fixée : elle s'étend
 * jusqu'à contenir le quota de systèmes générés.
 *
 * @see §2.1 et §2.2 du cahier des charges
 */
#[ORM\Entity(repositoryClass: GalaxyRepository::class)]
#[ORM\UniqueConstraint(name: 'galaxy_number_unique', fields: ['number'])]
#[Auditable]
final class Galaxy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, StarSystem> Non historisée : la génération y rattache des milliers de systèmes */
    #[Ignore]
    #[ORM\OneToMany(targetEntity: StarSystem::class, mappedBy: 'galaxy', fetch: 'EXTRA_LAZY')]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $systems;

    public function __construct(
        /** Numéro de la galaxie dans l'adresse logique (« Galaxie 1 ») */
        #[ORM\Column]
        private int $number,
        #[ORM\Column(length: 100)]
        #[Assert\NotBlank(message: 'Donnez un nom à la galaxie.')]
        #[Assert\Length(max: 100)]
        private string $name,
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException('Le numéro de galaxie doit être strictement positif.');
        }

        $this->systems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** Renommage depuis le panneau d'administration */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function __toString(): string
    {
        return \sprintf('Galaxie %d — %s', $this->number, $this->name);
    }

    /** @return Collection<int, StarSystem> */
    public function getSystems(): Collection
    {
        return $this->systems;
    }

    /** @internal Appelée par le constructeur de StarSystem pour garder les deux côtés de la relation synchronisés */
    public function addSystem(StarSystem $system): void
    {
        if (!$this->systems->contains($system)) {
            $this->systems->add($system);
        }
    }
}
