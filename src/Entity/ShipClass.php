<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShipClassRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Classe de combat d'un vaisseau (contenu de jeu, réglable dans le panneau) : intercepteur, bombardier, croiseur…
 * Elle fixe ses bonus et malus face aux autres classes (matrice, cf. §4.7).
 *
 * @see §4.5 du cahier des charges
 */
#[ORM\Entity(repositoryClass: ShipClassRepository::class)]
#[ORM\UniqueConstraint(name: 'ship_class_code_unique', fields: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ce code est déjà utilisé par une autre classe.')]
#[Auditable]
final class ShipClass implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    public function __construct(
        /** Identifiant stable (ex. « interceptor ») */
        #[ORM\Column(length: 40)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        #[Assert\Regex('/^[a-z][a-z0-9_]*$/', message: 'Le code ne contient que des minuscules, chiffres et « _ ».')]
        private string $code = '',
        #[ORM\Column(length: 60)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 60)]
        private string $name = '',
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
