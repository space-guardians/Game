<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Economy\Resources;
use App\Repository\ShipyardOrderRepository;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Auditable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Commande au chantier spatial d'une planète : N vaisseaux d'un type, payés à la commande, construits sur un poste
 * du chantier (les commandes d'un même poste s'enchaînent) et livrés ensemble à la fin. Supprimée à la livraison.
 *
 * @see §4.5 du cahier des charges
 */
#[ORM\Entity(repositoryClass: ShipyardOrderRepository::class)]
#[ORM\Index(name: 'shipyard_order_planet_idx', fields: ['planet', 'endsAt'])]
#[Auditable]
final class ShipyardOrder implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private float $paidMetal;

    #[ORM\Column]
    private float $paidCrystal;

    #[ORM\Column]
    private float $paidDeuterium;

    /** Événement qui livrera la commande */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ScheduledEvent $event = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private readonly Planet $planet,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private readonly ShipType $type,
        #[ORM\Column]
        private readonly int $quantity,
        /** Poste du chantier qui construit la commande (0 à postes − 1) */
        #[ORM\Column]
        private readonly int $slot,
        Resources $paid,
        #[ORM\Column]
        private readonly \DateTimeImmutable $orderedAt,
        /** Début de la construction : à la commande, ou à la fin de la commande précédente du même poste */
        #[ORM\Column]
        private readonly \DateTimeImmutable $startsAt,
        #[ORM\Column]
        private readonly \DateTimeImmutable $endsAt,
    ) {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Une commande porte sur au moins un vaisseau.');
        }
        $this->paidMetal = $paid->metal;
        $this->paidCrystal = $paid->crystal;
        $this->paidDeuterium = $paid->deuterium;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlanet(): Planet
    {
        return $this->planet;
    }

    public function getType(): ShipType
    {
        return $this->type;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getSlot(): int
    {
        return $this->slot;
    }

    public function getPaid(): Resources
    {
        return new Resources($this->paidMetal, $this->paidCrystal, $this->paidDeuterium);
    }

    public function getOrderedAt(): \DateTimeImmutable
    {
        return $this->orderedAt;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startsAt->getTimestamp();
    }

    public function isRunning(\DateTimeImmutable $now): bool
    {
        return $this->startsAt <= $now && $now < $this->endsAt;
    }

    public function getEvent(): ?ScheduledEvent
    {
        return $this->event;
    }

    public function attachEvent(ScheduledEvent $event): void
    {
        $this->event = $event;
    }

    public function __toString(): string
    {
        return \sprintf('%d × %s %s', $this->quantity, $this->type->getName(), $this->planet);
    }
}
