<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Market\PlanetListingStatus;
use App\Model\Economy\Resources;
use App\Repository\PlanetListingRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Planète mise sur le marché (§4.15), gelée tant que l'annonce court. Vente : contre des ressources, payées par
 * l'acheteur depuis sa planète et gardées pour le vendeur chez le marchand choisi. Échange : un autre empire propose une
 * de ses planètes, gelée à son tour ; le vendeur accepte (les deux planètes changent de mains) ou refuse. Auditée.
 */
#[ORM\Entity(repositoryClass: PlanetListingRepository::class)]
#[ORM\Index(name: 'planet_listing_status_idx', fields: ['status'])]
#[Auditable]
class PlanetListing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $priceMetal;

    #[ORM\Column]
    private float $priceCrystal;

    #[ORM\Column]
    private float $priceDeuterium;

    #[ORM\Column(length: 20, enumType: PlanetListingStatus::class)]
    private PlanetListingStatus $status = PlanetListingStatus::Open;

    /** Acheteur, ou empire qui propose un échange */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Empire $buyer = null;

    /** Planète proposée en échange, gelée le temps de la réponse */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Planet $offeredPlanet = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Empire $seller,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Planet $planet,
        /** Marchand qui gardera le prix pour le vendeur ; null pour un échange */
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Merchant $merchant,
        ?Resources $price,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
    ) {
        if ((null === $merchant) !== (null === $price) || (null !== $price && $price->isZero())) {
            throw new \InvalidArgumentException('Une vente fixe un prix et un marchand ; un échange, ni l’un ni l’autre.');
        }
        $price ??= Resources::zero();
        $this->priceMetal = $price->metal;
        $this->priceCrystal = $price->crystal;
        $this->priceDeuterium = $price->deuterium;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeller(): Empire
    {
        return $this->seller;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getMerchant(): ?Merchant
    {
        return $this->merchant;
    }

    public function isSwap(): bool
    {
        return null === $this->merchant;
    }

    public function getPrice(): Resources
    {
        return new Resources($this->priceMetal, $this->priceCrystal, $this->priceDeuterium);
    }

    public function getStatus(): PlanetListingStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return PlanetListingStatus::Open === $this->status || PlanetListingStatus::Offered === $this->status;
    }

    public function getBuyer(): ?Empire
    {
        return $this->buyer;
    }

    public function getOfferedPlanet(): ?Planet
    {
        return $this->offeredPlanet;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** Vente conclue avec un acheteur */
    public function sell(Empire $buyer, \DateTimeImmutable $at): void
    {
        if ($this->isSwap() || PlanetListingStatus::Open !== $this->status) {
            throw new \LogicException('Cette planète n’est plus en vente.');
        }
        $this->buyer = $buyer;
        $this->status = PlanetListingStatus::Completed;
        $this->closedAt = $at;
    }

    /** Proposition d'échange : une planète de l'autre empire contre celle-ci */
    public function offer(Empire $buyer, Planet $planet): void
    {
        if (!$this->isSwap() || PlanetListingStatus::Open !== $this->status) {
            throw new \LogicException('Cette planète n’attend pas de proposition d’échange.');
        }
        $this->buyer = $buyer;
        $this->offeredPlanet = $planet;
        $this->status = PlanetListingStatus::Offered;
    }

    /** Proposition refusée ou retirée : l'annonce redevient ouverte */
    public function dropOffer(): void
    {
        if (PlanetListingStatus::Offered !== $this->status) {
            throw new \LogicException('Aucune proposition d’échange en attente.');
        }
        $this->buyer = null;
        $this->offeredPlanet = null;
        $this->status = PlanetListingStatus::Open;
    }

    public function concludeSwap(\DateTimeImmutable $at): void
    {
        if (PlanetListingStatus::Offered !== $this->status) {
            throw new \LogicException('Aucune proposition d’échange en attente.');
        }
        $this->status = PlanetListingStatus::Completed;
        $this->closedAt = $at;
    }

    public function cancel(\DateTimeImmutable $at): void
    {
        if (PlanetListingStatus::Open !== $this->status) {
            throw new \LogicException('Seule une annonce sans proposition en attente peut être retirée.');
        }
        $this->status = PlanetListingStatus::Cancelled;
        $this->closedAt = $at;
    }
}
