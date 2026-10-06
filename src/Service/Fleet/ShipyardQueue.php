<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\ShipyardOrder;
use App\Enum\Economy\BuildingEffect;
use App\Exception\Economy\InsufficientResources;
use App\Exception\Fleet\ShipyardQueueFull;
use App\Exception\Research\MissingPrerequisites;
use App\Model\Economy\EconomySettings;
use App\Repository\ShipyardOrderRepository;
use App\Service\Economy\PlanetResources;
use App\Service\Research\PrerequisiteChecker;
use App\Service\Scheduling\EventScheduler;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Commandes au chantier spatial (§4.5) : N vaisseaux d'un type, prérequis remplis, payés à la commande (après
 * consolidation des ressources), construits sur le poste qui se libère le plus tôt et livrés ensemble à la fin
 * (ShipyardOrderCompletedHandler). Sous le verrou de la planète.
 */
final readonly class ShipyardQueue
{
    /** Commandes en cours et en attente au plus, par planète */
    public const int MAX_ORDERS = 10;

    /** Vaisseaux au plus par commande */
    public const int MAX_QUANTITY = 100_000;

    public function __construct(
        private ShipyardOrderRepository $orders,
        private PlanetResources $resources,
        private ShipyardRules $rules,
        private PrerequisiteChecker $prerequisites,
        private EconomySettings $settings,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
        private LockFactory $lockFactory,
    ) {}

    /**
     * @throws MissingPrerequisites
     * @throws ShipyardQueueFull
     * @throws InsufficientResources
     */
    public function order(Planet $planet, ShipType $type, int $quantity): ShipyardOrder
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new \InvalidArgumentException(\sprintf('Une commande porte sur 1 à %d vaisseaux.', self::MAX_QUANTITY));
        }
        $lock = $this->lockFactory->createLock(ScheduledEventResolver::planetLockKey((int) $planet->getId()), ttl: 30.0);
        $lock->acquire(true);

        try {
            $missing = $this->prerequisites->missing($type, $planet);
            if ([] !== $missing) {
                throw new MissingPrerequisites($missing);
            }
            $pending = $this->orders->findForPlanet($planet);
            if (\count($pending) >= self::MAX_ORDERS) {
                throw new ShipyardQueueFull(self::MAX_ORDERS);
            }

            $cost = $type->getCost()->times($quantity);
            $snapshot = $this->resources->settle($planet);
            if (!$snapshot->amounts->covers($cost)) {
                throw new InsufficientResources($snapshot->amounts->shortfall($cost));
            }

            $now = $snapshot->at;
            $busyUntil = [];
            foreach ($pending as $order) {
                $busyUntil[$order->getSlot()] = max($busyUntil[$order->getSlot()] ?? $order->getEndsAt(), $order->getEndsAt());
            }
            [$slot, $startsAt] = $this->rules->nextSlot($this->slots($planet), $busyUntil, $now);
            $endsAt = $startsAt->modify(\sprintf('+%d seconds', $this->unitSeconds($planet, $type) * $quantity));

            return $this->entityManager->wrapInTransaction(function () use ($planet, $type, $quantity, $slot, $cost, $snapshot, $now, $startsAt, $endsAt): ShipyardOrder {
                $planet->storeResources($snapshot->amounts->minus($cost), $now);
                $order = new ShipyardOrder($planet, $type, $quantity, $slot, $cost, $now, $startsAt, $endsAt);
                $this->entityManager->persist($order);
                $this->entityManager->flush();
                // Type et nombre recopiés : la commande quitte la file à la livraison, la notification en a besoin ensuite
                $order->attachEvent($this->scheduler->schedule(ShipyardOrderCompletedHandler::TYPE, $endsAt, $planet, [
                    'order' => $order->getId(),
                    'ship' => $type->getCode(),
                    'quantity' => $quantity,
                ]));
                $this->entityManager->flush();

                return $order;
            });
        } finally {
            $lock->release();
        }
    }

    /** Durée de construction d'un vaisseau du type sur la planète, selon son chantier et ses nanites */
    public function unitSeconds(Planet $planet, ShipType $type): int
    {
        return $this->rules->unitSeconds(
            $type->getCost(),
            $this->level($planet, BuildingEffect::Shipyard),
            $this->level($planet, BuildingEffect::NaniteFactory),
            $this->settings->universeSpeed,
        );
    }

    /** Postes du chantier de la planète */
    public function slots(Planet $planet): int
    {
        return $this->rules->slots($this->level($planet, BuildingEffect::Shipyard));
    }

    private function level(Planet $planet, BuildingEffect $effect): int
    {
        $level = 0;
        foreach ($planet->getBuildings() as $building) {
            if ($effect === $building->getType()->getEffect()) {
                $level += $building->getLevel();
            }
        }

        return $level;
    }
}
