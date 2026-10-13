<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Fleet\SpacePosition;
use App\Repository\MerchantRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Marchand PNJ (§4.15) : point de dépôt et de retrait neutre du marché, sur une station à position fixe d'un système
 * (un au plus par système). Il n'est pas une planète : aucune mission d'attaque, de pillage ni d'espionnage ne peut le
 * viser, et son stockage est illimité (`MerchantHolding`, par empire bénéficiaire). Audité (§5.6.3).
 */
#[ORM\Entity(repositoryClass: MerchantRepository::class)]
#[ORM\UniqueConstraint(name: 'merchant_system_unique', fields: ['system'])]
#[Auditable]
class Merchant implements \Stringable
{
    public const int NAME_MAX_LENGTH = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly StarSystem $system,
        #[ORM\Column(length: self::NAME_MAX_LENGTH)]
        #[Assert\NotBlank(message: 'Donnez un nom au marchand.')]
        #[Assert\Length(max: self::NAME_MAX_LENGTH)]
        private string $name,
        /** Station : coordonnées locales dans le système */
        #[ORM\Column]
        private readonly float $localX,
        #[ORM\Column]
        private readonly float $localY,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSystem(): StarSystem
    {
        return $this->system;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getLocalX(): float
    {
        return $this->localX;
    }

    public function getLocalY(): float
    {
        return $this->localY;
    }

    /** Destination des flottes qui viennent déposer ou retirer des ressources */
    public function position(): SpacePosition
    {
        return SpacePosition::inSystem($this->system, $this->localX, $this->localY);
    }

    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->name, $this->system);
    }
}
