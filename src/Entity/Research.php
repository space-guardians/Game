<?php

declare(strict_types=1);

namespace App\Entity;

use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Niveau d'une technologie pour un empire (la recherche vaut pour toutes ses planètes) ; sans ligne, le niveau
 * est 0.
 *
 * @see §4.4 du cahier des charges
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'research_unique', fields: ['empire', 'technology'])]
#[Auditable]
final class Research implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'researches')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
        /** Une technologie recherchée par un empire ne se supprime pas (clé étrangère restrictive) */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly Technology $technology,
        #[ORM\Column]
        private int $level = 0,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getTechnology(): Technology
    {
        return $this->technology;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        if ($level < 0) {
            throw new \InvalidArgumentException('Un niveau de recherche ne peut pas être négatif.');
        }
        $this->level = $level;
    }

    public function __toString(): string
    {
        return \sprintf('%s niv. %d (%s)', $this->technology->getName(), $this->level, $this->empire->getName());
    }
}
