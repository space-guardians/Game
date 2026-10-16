<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Factory\EmpireFactory;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetRepository;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Flottes à l'arrêt autour d'un lieu (batailles, espionnage, scanner) : le tri par distance se fait en base (§5.2).
 */
final class FleetRepositoryTest extends KernelTestCase
{
    use Factories;

    public function testFindsOnlyStoppedFleetsAroundThePointInItsGalaxy(): void
    {
        $empire = EmpireFactory::createOne();
        $system = $empire->getHomePlanet()->getSystem();
        $galaxyId = (int) $system->getGalaxy()->getId();
        $center = $system->getPosition();
        // Point d'un système : ancre (l'étoile) + position locale
        $inSystem = $this->fleetAt($empire, SpacePosition::inSystem($system, 30.0, -20.0));
        $stranded = $this->fleetAt($empire, SpacePosition::deepSpace($galaxyId, new GlobalPosition($center->x + 35.0, $center->y - 20.0)));
        $stranded->strand($stranded->getLocation());
        $far = $this->fleetAt($empire, SpacePosition::deepSpace($galaxyId, new GlobalPosition($center->x + 400.0, $center->y)));
        $flying = $this->fleetAt($empire, SpacePosition::inSystem($system, 30.0, -20.0));
        $flying->depart();
        $otherGalaxy = $this->fleetAt(EmpireFactory::createOne(), SpacePosition::deepSpace((int) EmpireFactory::createOne()->getHomePlanet()->getSystem()->getGalaxy()->getId(), new GlobalPosition($center->x + 30.0, $center->y - 20.0)));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $found = self::getContainer()->get(FleetRepository::class)->findStoppedNear($galaxyId, new GlobalPosition($center->x + 30.0, $center->y - 20.0), 10.0);

        $ids = array_map(static fn(Fleet $fleet): ?int => $fleet->getId(), $found);
        self::assertSame([$inSystem->getId(), $stranded->getId()], $ids);
        self::assertNotContains($far->getId(), $ids);
        self::assertNotContains($flying->getId(), $ids);
        self::assertNotContains($otherGalaxy->getId(), $ids);
    }

    private function fleetAt(Empire $empire, SpacePosition $position): Fleet
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($type, 1);
        $fleet->arriveAt(SpaceLocation::of($position), null);
        self::getContainer()->get(EntityManagerInterface::class)->persist($fleet);

        return $fleet;
    }
}
