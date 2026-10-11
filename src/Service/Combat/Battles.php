<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\Planet;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Repository\BattleRepository;
use App\Service\Scheduling\EventScheduler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Batailles programmées (§4.7) : une attaque lancée vers une position devient une bataille à ce lieu, résolue à
 * l'arrivée du premier assaillant ; une attaque qui y arrive avant cette heure la rejoint. La résolution est un
 * événement planifié (BattleResolutionHandler).
 */
final readonly class Battles
{
    /** Écart toléré entre deux positions pour les tenir pour le même lieu de bataille */
    public const float SAME_PLACE_TOLERANCE = 1.0;

    public function __construct(
        private BattleRepository $battles,
        private EventScheduler $scheduler,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Départ d'une flotte en attaque (« Rejoindre une bataille ») : elle rejoint la bataille programmée au lieu visé
     * si elle y arrive à temps, sinon elle en programme une nouvelle à son heure d'arrivée.
     */
    public function engage(Fleet $fleet, FleetMovement $movement): Battle
    {
        $order = $movement->getOrder();
        $destination = $order->getDestination();
        $arrival = $movement->getArrivesAt();

        $battle = null;
        foreach ($this->battles->findScheduledInGalaxy($destination->galaxyId) as $candidate) {
            if ($candidate->getResolvesAt() >= $arrival && self::samePlace($candidate->getLocation(), $destination)) {
                $battle = $candidate;

                break;
            }
        }

        if (null === $battle) {
            $battle = new Battle($destination, $order->getDestinationLabel(), $movement->getDepartedAt(), $arrival);
            $battle->join($fleet, CombatSide::Attacker, $movement->getDepartedAt(), $movement->getSpeedPercent());
            $this->entityManager->persist($battle);
            $this->entityManager->flush();
            // Bataille sur une planète : sous le verrou de la planète, comme les arrivées qui y mènent
            $planet = null === $destination->planetId ? null : $this->entityManager->find(Planet::class, $destination->planetId);
            $this->scheduler->schedule(BattleResolutionHandler::TYPE, $arrival, $planet, ['battle' => $battle->getId()]);

            return $battle;
        }

        $battle->join($fleet, CombatSide::Attacker, $movement->getDepartedAt(), $movement->getSpeedPercent());
        $this->entityManager->flush();

        return $battle;
    }

    public static function samePlace(SpaceLocation $a, SpaceLocation $b): bool
    {
        return $a->galaxyId === $b->galaxyId
            && $a->toPosition()->global()->distanceTo($b->toPosition()->global()) <= self::SAME_PLACE_TOLERANCE;
    }
}
