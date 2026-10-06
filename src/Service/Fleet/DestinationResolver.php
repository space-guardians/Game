<?php

declare(strict_types=1);

namespace App\Service\Fleet;

use App\Entity\Galaxy;
use App\Entity\Planet;
use App\Entity\SpaceLocation;
use App\Entity\StarSystem;
use App\Model\Fleet\OrderCoordinates;
use App\Model\Fleet\SpacePosition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Destination d'un ordre à partir des coordonnées saisies par le joueur (§2.1) : « galaxie:système:position » vise une
 * planète, « galaxie:système » le système entier (§4.6.2). Et inversement, coordonnées d'une position enregistrée.
 */
final readonly class DestinationResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return array{position: SpacePosition, label: string}|null null si les coordonnées ne désignent rien
     */
    public function resolve(int $galaxyNumber, int $systemNumber, ?int $orbit): ?array
    {
        $galaxy = $this->entityManager->getRepository(Galaxy::class)->findOneBy(['number' => $galaxyNumber]);
        $system = null === $galaxy ? null : $this->entityManager->getRepository(StarSystem::class)->findOneBy(['galaxy' => $galaxy, 'number' => $systemNumber]);
        if (!$system instanceof StarSystem) {
            return null;
        }
        if (null === $orbit) {
            return ['position' => SpacePosition::system($system), 'label' => \sprintf('système %d:%d', $galaxyNumber, $systemNumber)];
        }

        $planet = $this->entityManager->getRepository(Planet::class)->findOneBy(['system' => $system, 'position.orbit' => $orbit]);

        return $planet instanceof Planet ? ['position' => SpacePosition::planet($planet), 'label' => (string) $planet->getAddress()] : null;
    }

    /**
     * Coordonnées d'une position enregistrée (position d'une flotte), pour préremplir un ordre ; null hors système, où
     * il n'y a pas de coordonnées à saisir.
     */
    public function coordinatesOf(SpaceLocation $location): ?OrderCoordinates
    {
        $system = null === $location->systemId ? null : $this->entityManager->find(StarSystem::class, $location->systemId);
        if (!$system instanceof StarSystem) {
            return null;
        }
        $planet = null === $location->planetId ? null : $this->entityManager->find(Planet::class, $location->planetId);

        return new OrderCoordinates($system->getGalaxy()->getNumber(), $system->getNumber(), $planet?->getPosition()->orbit);
    }
}
