<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Universe;

use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetAction;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetDispatch;
use App\Service\Universe\PlanetLocks;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Verrou d'une planète (§5.2) : sous le verrou, une requête travaille sur l'état écrit par le worker, pas sur celui
 * qu'elle avait chargé avant (mise à jour perdue).
 */
final class PlanetLocksTest extends KernelTestCase
{
    use Factories;

    public function testStockWrittenMeanwhileByAnotherProcessIsReadUnderTheLock(): void
    {
        $planet = EmpireFactory::createOne()->getHomePlanet();
        $before = $planet->getResources();
        // Le worker livre un transport pendant que la requête tient l'ancienne entité
        $this->connection()->executeStatement('UPDATE planet SET metal = metal + 3000 WHERE id = ?', [$planet->getId()]);

        $lock = $this->locks()->acquire($planet);

        self::assertSame($before->metal + 3000, $planet->getResources()->metal);
        self::assertSame($before->crystal, $planet->getResources()->crystal);
        // Valeur lue, pas une modification : rien à réécrire
        $unitOfWork = $this->entityManager()->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        self::assertSame([], $unitOfWork->getEntityChangeSet($planet));
        $lock->release();
    }

    public function testStockChangedByTheCallerAndNotYetWrittenIsKept(): void
    {
        $planet = EmpireFactory::createOne()->getHomePlanet();
        $planet->storeResources(new Resources(1234, 0, 0), new \DateTimeImmutable('2026-10-16 12:00:00'));

        $this->locks()->acquire($planet)->release();

        self::assertSame(1234.0, $planet->getResources()->metal);
    }

    public function testFleetThatLeftMeanwhileCannotBeDispatchedAgain(): void
    {
        $empire = EmpireFactory::createOne();
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($type, 2);
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();
        // Partie entre-temps dans un autre processus (raid, suite d'ordres)
        $this->connection()->executeStatement('UPDATE fleet SET status = ? WHERE id = ?', ['in_flight', $fleet->getId()]);

        $this->expectExceptionObject(new InvalidFleetMission('La flotte « Escadre » n’est pas stationnée : elle exécute déjà une mission.'));

        self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::system($empire->getHomePlanet()->getSystem()), FleetAction::Station, 'système')], 100, new Resources());
    }

    private function locks(): PlanetLocks
    {
        return self::getContainer()->get(PlanetLocks::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
