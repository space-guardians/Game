<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\FairPlay;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Enum\Fleet\FleetAction;
use App\Exception\FairPlay\ProtectionRefused;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Economy\PlanetEconomy;
use App\Service\FairPlay\ProtectionRules;
use App\Service\FairPlay\Protections;
use App\Service\Fleet\FleetDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Bouclier débutant et mode vacances (§4.13).
 */
final class ProtectionsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 18:00:00');
    }

    public function testANewbieCannotBeAttackedNorSpiedUntilItsShieldEnds(): void
    {
        $veteran = EmpireFactory::createOne(['name' => 'Ana']);
        $newbie = $this->neighbour($veteran, 'Bob', $this->clock->now()->modify('-2 days'));

        try {
            $this->attack($veteran, $newbie);
            self::fail('Attaque d’un débutant acceptée.');
        } catch (InvalidFleetMission $refusal) {
            self::assertSame('Ordre 1 : Bob bénéficie du bouclier débutant jusqu’au 20/10/2026 18:00.', $refusal->getMessage());
        }

        $this->clock->sleep(6 * 86400);
        self::assertNull(self::getContainer()->get(Protections::class)->refusal($newbie));
        $this->attack($veteran, $newbie);
    }

    public function testVacationReducesProductionProtectsAndKeepsFleetsHome(): void
    {
        $empire = EmpireFactory::createOne(['name' => 'Bob']);
        $planet = $empire->getHomePlanet();
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $planet->setBuildingLevel($mine, 10);
        $fleet = $this->fleet($empire);
        $economy = self::getContainer()->get(PlanetEconomy::class);
        $rate = $economy->output($planet)->hourlyProduction->metal;

        self::getContainer()->get(Protections::class)->startVacation($empire);

        self::assertTrue($empire->isOnVacation());
        self::assertEqualsWithDelta($rate * ProtectionRules::VACATION_PRODUCTION, $economy->output($planet)->hourlyProduction->metal, 0.01);
        self::assertSame('Bob est en mode vacances : ses planètes sont protégées.', self::getContainer()->get(Protections::class)->refusal($empire));
        try {
            self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::system($planet->getSystem()), FleetAction::Station, 'système')], 100, new Resources());
            self::fail('Flotte partie en mode vacances.');
        } catch (InvalidFleetMission $refusal) {
            self::assertSame('En mode vacances, vos flottes restent à quai.', $refusal->getMessage());
        }

        // Au moins 48 heures
        try {
            self::getContainer()->get(Protections::class)->endVacation($empire);
            self::fail('Vacances terminées avant la durée minimale.');
        } catch (ProtectionRefused $refusal) {
            self::assertSame('Le mode vacances dure au moins 48 heures : fin possible à partir du 17/10/2026 18:00.', $refusal->getMessage());
        }
        $this->clock->sleep(48 * 3600);
        self::getContainer()->get(Protections::class)->endVacation($empire);
        self::assertFalse($empire->isOnVacation());
        self::assertEqualsWithDelta($rate, $economy->output($planet)->hourlyProduction->metal, 0.01);
    }

    public function testVacationIsRefusedWithAFleetAwayOrUnderAttack(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = $this->neighbour($ana, 'Bob', $this->clock->now()->modify('-1 year'));
        $this->attack($ana, $bob);

        // L'assaillant a une flotte en vol ; le défenseur est sous la menace
        foreach ([[$ana, 'La flotte « Escadre » n’est pas rentrée sur une de vos planètes : rappelez-la d’abord.'], [$bob, 'Une attaque est en route vers vos planètes : le mode vacances ne s’active pas sous la menace.']] as [$empire, $expected]) {
            try {
                self::getContainer()->get(Protections::class)->startVacation($empire);
                self::fail('Mode vacances activé à tort.');
            } catch (ProtectionRefused $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }
    }

    private function attack(Empire $attacker, Empire $target): void
    {
        self::getContainer()->get(FleetDispatch::class)->dispatch($this->fleet($attacker), [new MissionStep(SpacePosition::planet($target->getHomePlanet()), FleetAction::JoinBattle, $target->getName())], 100, new Resources());
    }

    private function neighbour(Empire $of, string $name, \DateTimeImmutable $foundedAt): Empire
    {
        $system = $of->getHomePlanet()->getSystem();

        return EmpireFactory::createOne(['name' => $name, 'foundedAt' => $foundedAt, 'homePlanet' => PlanetFactory::new(['system' => StarSystemFactory::new(['galaxy' => $system->getGalaxy(), 'position' => new GlobalPosition($system->getPosition()->x + 600, $system->getPosition()->y)])])]);
    }

    private function fleet(Empire $empire): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), $this->clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet->addShips($type, 3);
        $fleet->refuel($fleet->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }
}
