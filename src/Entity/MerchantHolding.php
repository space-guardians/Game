<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\MerchantHoldingRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ressources gardées par un marchand pour un empire (§4.15) : ce que cet empire, et lui seul, peut venir retirer.
 * Stockage illimité. Une ligne par marchand et par empire. Auditée (§5.6.3).
 */
#[ORM\Entity(repositoryClass: MerchantHoldingRepository::class)]
#[ORM\UniqueConstraint(name: 'merchant_holding_unique', fields: ['merchant', 'empire'])]
#[Auditable]
class MerchantHolding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $metal = 0.0;

    #[ORM\Column]
    private float $crystal = 0.0;

    #[ORM\Column]
    private float $deuterium = 0.0;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Merchant $merchant,
        /** Bénéficiaire : seul empire autorisé à retirer ces ressources */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Empire $empire,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    public function getEmpire(): Empire
    {
        return $this->empire;
    }

    public function getResources(): Resources
    {
        return new Resources($this->metal, $this->crystal, $this->deuterium);
    }

    public function isEmpty(): bool
    {
        return $this->getResources()->isZero();
    }

    public function credit(Resources $resources): void
    {
        $this->store($this->getResources()->plus($resources));
    }

    /** @throws \InvalidArgumentException si le marchand ne garde pas assez de ressources pour cet empire */
    public function withdraw(Resources $resources): void
    {
        if (!$this->getResources()->covers($resources)) {
            throw new \InvalidArgumentException('Le marchand ne garde pas autant de ressources pour vous.');
        }
        $this->store($this->getResources()->minus($resources));
    }

    private function store(Resources $resources): void
    {
        $this->metal = $resources->metal;
        $this->crystal = $resources->crystal;
        $this->deuterium = $resources->deuterium;
    }
}
