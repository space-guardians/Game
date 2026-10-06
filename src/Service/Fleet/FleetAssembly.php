<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Exception\Fleet\InvalidFleetComposition;
use App\Repository\FleetRepository;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Constitution et dissolution des flottes (§4.5) : une flotte prélève ses vaisseaux dans l'inventaire de la planète,
 * et les y rend quand elle est dissoute. Sous le verrou de la planète (une livraison du chantier peut tomber en même
 * temps).
 */
final readonly class FleetAssembly
{
    /** Flottes au plus par empire */
    public const int MAX_FLEETS = 50;

    public function __construct(
        private FleetRepository $fleets,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<array{type: ShipType, quantity: int}> $ships vaisseaux à prélever ; les quantités nulles sont ignorées
     *
     * @throws InvalidFleetComposition
     */
    public function assemble(Planet $planet, string $name, array $ships): Fleet
    {
        $empire = $planet->getOwner() ?? throw new InvalidFleetComposition('Cette planète n’appartient à aucun empire.');
        $name = trim($name);
        if ('' === $name) {
            $name = \sprintf('Flotte %d', $this->fleets->countOwnedBy($empire) + 1);
        }
        if (mb_strlen($name) > Fleet::NAME_MAX_LENGTH) {
            throw new InvalidFleetComposition(\sprintf('Le nom d’une flotte compte au plus %d caractères.', Fleet::NAME_MAX_LENGTH));
        }
        $ships = array_values(array_filter($ships, static fn(array $ship): bool => 0 !== $ship['quantity']));
        if ([] === $ships) {
            throw new InvalidFleetComposition('Choisissez au moins un vaisseau.');
        }

        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            if ($this->fleets->countOwnedBy($empire) >= self::MAX_FLEETS) {
                throw new InvalidFleetComposition(\sprintf('Un empire compte au plus %d flottes.', self::MAX_FLEETS));
            }
            // Inventaire relu sous le verrou : une livraison ou une autre flotte a pu le changer entre-temps
            foreach ($planet->getShips() as $stock) {
                if ($this->entityManager->contains($stock)) {
                    $this->entityManager->refresh($stock);
                }
            }
            foreach ($ships as ['type' => $type, 'quantity' => $quantity]) {
                $available = $planet->shipCount($type);
                if ($quantity < 0 || $quantity > $available) {
                    throw new InvalidFleetComposition(\sprintf('%s : %d disponible(s), %d demandé(s).', $type->getName(), $available, $quantity));
                }
            }

            return $this->entityManager->wrapInTransaction(function () use ($empire, $name, $planet, $ships): Fleet {
                $fleet = new Fleet($empire, $name, $planet, $this->clock->now());
                foreach ($ships as ['type' => $type, 'quantity' => $quantity]) {
                    $planet->removeShips($type, $quantity);
                    $fleet->addShips($type, $quantity);
                }
                $this->entityManager->persist($fleet);
                $this->entityManager->flush();

                return $fleet;
            });
        } finally {
            $lock->release();
        }
    }

    /** Dissout une flotte stationnée : ses vaisseaux rejoignent l'inventaire de sa planète */
    public function disband(Fleet $fleet): void
    {
        $planet = $fleet->getPlanet();
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            $this->entityManager->wrapInTransaction(function () use ($fleet, $planet): void {
                foreach ($fleet->getShips() as $ships) {
                    $planet->addShips($ships->getType(), $ships->getQuantity());
                }
                $this->entityManager->remove($fleet);
                $this->entityManager->flush();
            });
        } finally {
            $lock->release();
        }
    }
}
