<?php

declare(strict_types=1);

namespace App\Service\Combat;

use App\Entity\Battle;
use App\Entity\Fleet;
use App\Entity\SpaceLocation;
use App\Entity\StarSystem;
use App\Enum\Combat\CombatSide;
use App\Enum\Combat\InterceptionOutcome;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Interception au niveau d'un système (§4.6.2) : une flotte stationnée au niveau d'un système (ni planète ni point
 * précis) intercepte toute flotte hostile qui y entre depuis l'extérieur, quelle que soit sa destination dans le
 * système — protégeant ainsi toutes ses planètes. Le combat a lieu à l'échelle du système, avant toute action à
 * destination ; les défenses planétaires n'y participent pas (elles ne défendent que leur planète, si une flotte
 * hostile parvient jusqu'à elle). L'intrus qui anéantit les gardes poursuit ; sinon il est arrêté au système.
 */
final readonly class Interceptions
{
    public function __construct(
        private FleetRepository $fleets,
        private Allegiances $allegiances,
        private BattleFight $fight,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Entrée d'une flotte dans le système de sa destination, depuis sa position de départ.
     */
    public function enter(Fleet $fleet, SpaceLocation $from, SpaceLocation $destination, \DateTimeImmutable $at): InterceptionOutcome
    {
        $systemId = $destination->systemId;
        // Hors système, ou déjà à l'intérieur : rien à franchir
        if (null === $systemId || $from->systemId === $systemId) {
            return InterceptionOutcome::None;
        }
        $guards = array_values(array_filter(
            $this->fleets->findGuarding($systemId),
            fn(Fleet $guard): bool => $guard !== $fleet && !$this->allegiances->allied($guard->getEmpire(), $fleet->getEmpire()),
        ));
        $system = $this->entityManager->find(StarSystem::class, $systemId);
        if ([] === $guards || !$system instanceof StarSystem) {
            return InterceptionOutcome::None;
        }

        $location = SpaceLocation::of(SpacePosition::system($system));
        $battle = new Battle($location, \sprintf('système %d:%d (interception)', $system->getGalaxy()->getNumber(), $system->getNumber()), $at, $at);
        $this->entityManager->persist($battle);
        $result = $this->fight->fight($battle, [$fleet], $guards, $at);

        if ($fleet->isEmpty()) {
            return InterceptionOutcome::Destroyed;
        }
        if ($result->isDestroyed(CombatSide::Defender)) {
            return InterceptionOutcome::Broken;
        }
        // Arrêtée à la lisière du système, au niveau du système
        $fleet->arriveAt($location, null);

        return InterceptionOutcome::Stopped;
    }
}
