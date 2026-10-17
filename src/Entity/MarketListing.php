<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Market\MarketListingStatus;
use App\Model\Economy\Resources;
use App\Repository\MarketListingRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Annonce du marché (§4.15) : un vendeur propose des ressources contre d'autres, chez un marchand. Un acheteur s'engage
 * (`accept`), puis chacun livre son dû au marchand par une mission de transport ; la transaction n'est validée
 * (`complete`) qu'une fois les deux livraisons faites, chacun pouvant ensuite retirer ce qui lui revient
 * (`MerchantHolding`). Le vendeur peut livrer dès la publication ; il retire son annonce tant qu'aucun acheteur ne
 * s'est engagé (ce qu'il a livré lui revient alors). Auditée (§5.6.3).
 */
#[ORM\Entity(repositoryClass: MarketListingRepository::class)]
#[ORM\Index(name: 'market_listing_status_idx', fields: ['status'])]
#[Auditable]
class MarketListing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $offeredMetal;

    #[ORM\Column]
    private float $offeredCrystal;

    #[ORM\Column]
    private float $offeredDeuterium;

    #[ORM\Column]
    private float $askedMetal;

    #[ORM\Column]
    private float $askedCrystal;

    #[ORM\Column]
    private float $askedDeuterium;

    /** Antimatière (§4.14) proposée par le vendeur et demandée en contrepartie, en plus des ressources classiques */
    #[ORM\Column(options: ['default' => 0])]
    private float $offeredAntimatter = 0.0;

    #[ORM\Column(options: ['default' => 0])]
    private float $askedAntimatter = 0.0;

    #[ORM\Column(length: 20, enumType: MarketListingStatus::class)]
    private MarketListingStatus $status = MarketListingStatus::Open;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Empire $buyer = null;

    #[ORM\Column]
    private bool $sellerDelivered = false;

    #[ORM\Column]
    private bool $buyerDelivered = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /** Validation ou retrait */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Empire $seller,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Merchant $merchant,
        Resources $offered,
        Resources $asked,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        float $offeredAntimatter = 0.0,
        float $askedAntimatter = 0.0,
    ) {
        if (($offered->isZero() && $offeredAntimatter <= 0) || ($asked->isZero() && $askedAntimatter <= 0)) {
            throw new \InvalidArgumentException('Une annonce propose des ressources contre d’autres ressources.');
        }
        $this->offeredAntimatter = max(0.0, $offeredAntimatter);
        $this->askedAntimatter = max(0.0, $askedAntimatter);
        $this->offeredMetal = $offered->metal;
        $this->offeredCrystal = $offered->crystal;
        $this->offeredDeuterium = $offered->deuterium;
        $this->askedMetal = $asked->metal;
        $this->askedCrystal = $asked->crystal;
        $this->askedDeuterium = $asked->deuterium;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSeller(): Empire
    {
        return $this->seller;
    }

    public function getMerchant(): Merchant
    {
        return $this->merchant;
    }

    /** Ressources que le vendeur livre au marchand, destinées à l'acheteur */
    public function getOffered(): Resources
    {
        return new Resources($this->offeredMetal, $this->offeredCrystal, $this->offeredDeuterium);
    }

    /** Contrepartie que l'acheteur livre au marchand, destinée au vendeur */
    public function getAsked(): Resources
    {
        return new Resources($this->askedMetal, $this->askedCrystal, $this->askedDeuterium);
    }

    public function getOfferedAntimatter(): float
    {
        return $this->offeredAntimatter;
    }

    public function getAskedAntimatter(): float
    {
        return $this->askedAntimatter;
    }

    /** Antimatière que cet empire doit livrer au marchand */
    public function antimatterDueFrom(Empire $party): float
    {
        return match ($party) {
            $this->seller => $this->offeredAntimatter,
            $this->buyer => $this->askedAntimatter,
            default => throw new \LogicException('Cet empire ne prend pas part à la transaction.'),
        };
    }

    public function getStatus(): MarketListingStatus
    {
        return $this->status;
    }

    public function getBuyer(): ?Empire
    {
        return $this->buyer;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isDeliveredBy(Empire $party): bool
    {
        return match ($party) {
            $this->seller => $this->sellerDelivered,
            $this->buyer => $this->buyerDelivered,
            default => false,
        };
    }

    /** Ce que la partie doit livrer au marchand */
    public function dueFrom(Empire $party): Resources
    {
        return match ($party) {
            $this->seller => $this->getOffered(),
            $this->buyer => $this->getAsked(),
            default => throw new \LogicException('Cet empire ne prend pas part à la transaction.'),
        };
    }

    public function accept(Empire $buyer, \DateTimeImmutable $at): void
    {
        if (MarketListingStatus::Open !== $this->status) {
            throw new \LogicException('Cette annonce n’est plus ouverte.');
        }
        if ($buyer === $this->seller) {
            throw new \LogicException('Un vendeur n’achète pas sa propre annonce.');
        }
        $this->buyer = $buyer;
        $this->status = MarketListingStatus::Accepted;
        $this->acceptedAt = $at;
    }

    /** Une livraison de cette partie est-elle attendue ? Le vendeur peut livrer dès la publication, l'acheteur une fois engagé */
    public function awaitsDeliveryFrom(Empire $party): bool
    {
        return match ($party) {
            $this->seller => !$this->sellerDelivered && \in_array($this->status, [MarketListingStatus::Open, MarketListingStatus::Accepted], true),
            $this->buyer => !$this->buyerDelivered && MarketListingStatus::Accepted === $this->status,
            default => false,
        };
    }

    /** Livraison d'une partie notée ; renvoie vrai quand les deux ont livré (transaction à valider) */
    public function markDelivered(Empire $party): bool
    {
        if (!$this->awaitsDeliveryFrom($party)) {
            throw new \LogicException('Aucune livraison n’est attendue de cet empire pour cette annonce.');
        }
        match ($party) {
            $this->seller => $this->sellerDelivered = true,
            $this->buyer => $this->buyerDelivered = true,
            default => throw new \LogicException('Cet empire ne prend pas part à la transaction.'),
        };

        return $this->sellerDelivered && $this->buyerDelivered;
    }

    public function complete(\DateTimeImmutable $at): void
    {
        if (MarketListingStatus::Accepted !== $this->status || !$this->sellerDelivered || !$this->buyerDelivered) {
            throw new \LogicException('Une transaction n’est validée qu’une fois les deux livraisons faites.');
        }
        $this->status = MarketListingStatus::Completed;
        $this->closedAt = $at;
    }

    /** Retire l'annonce ; renvoie vrai si le vendeur avait déjà livré (ses ressources lui reviennent) */
    public function cancel(\DateTimeImmutable $at): bool
    {
        if (MarketListingStatus::Open !== $this->status) {
            throw new \LogicException('Seule une annonce encore ouverte peut être retirée.');
        }
        $this->status = MarketListingStatus::Cancelled;
        $this->closedAt = $at;

        return $this->sellerDelivered;
    }
}
