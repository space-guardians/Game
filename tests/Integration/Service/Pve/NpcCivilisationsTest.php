<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Repository\EmpireRepository;
use App\Repository\FleetRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Service\Pve\NpcCivilisations;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fondation d'une civilisation non-joueuse (§4.14) : planète libre, stock, défenses et flotte de départ.
 */
final class NpcCivilisationsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testAMinorCivilisationSettlesAFreePlanetWithDefensesAndAFleet(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-15 10:00:00');
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider');
        \assert(null !== $profile);
        $planet = PlanetFactory::createOne();

        $npc = self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Pillards', $planet);

        self::assertTrue($npc->isNpc());
        self::assertSame($npc, $planet->getOwner());
        self::assertSame(NpcCivilisations::STARTING_STOCK, $planet->getResources()->metal);
        $launcher = self::getContainer()->get(\App\Repository\ShipTypeRepository::class)->findOneByCode('rocket_launcher');
        \assert(null !== $launcher);
        self::assertSame(20, $planet->shipCount($launcher));
        $fleets = self::getContainer()->get(FleetRepository::class)->findBy(['empire' => $npc]);
        self::assertCount(1, $fleets);
        // assertEquals : l'ordre des types dépend du chargement en base
        self::assertEquals(['light_fighter' => 15, 'cruiser' => 3, 'small_cargo' => 5], $fleets[0]->shipCounts());
        self::assertSame($fleets[0]->tankCapacity(), (int) $fleets[0]->getFuel());

        // Jamais sur une planète occupée
        $this->expectException(\DomainException::class);
        self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Intrus', EmpireFactory::createOne()->getHomePlanet());
    }

    public function testTheCommandFoundsACivilisationOnAFreePlanet(): void
    {
        $planet = PlanetFactory::createOne();
        $galaxy = $planet->getSystem()->getGalaxy()->getNumber();
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:npc:found'));

        $tester->execute(['profile' => 'minor_raider', 'name' => 'Pillards', '--galaxy' => (string) $galaxy]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Civilisation « Pillards » (Pillards des confins) fondée sur', $tester->getDisplay());
        self::assertTrue(self::getContainer()->get(EmpireRepository::class)->findOneBy(['name' => 'Pillards'])?->isNpc());

        $tester->execute(['profile' => 'inconnu', 'name' => 'X', '--galaxy' => (string) $galaxy]);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
