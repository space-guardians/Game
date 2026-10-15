<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Balance;

use App\Command\SimulateCombatCommand;
use App\Enum\Combat\AttackAngle;
use App\Service\Balance\CombatSimulator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Outils de la passe d'équilibrage (§7) : simulations de combat reproductibles sur le contenu en base, et tableaux de
 * progression.
 */
final class CombatSimulatorTest extends KernelTestCase
{
    public function testSimulationsAreReproducibleAndReflectTheContent(): void
    {
        self::bootKernel();
        $simulator = self::getContainer()->get(CombatSimulator::class);

        $crushing = $simulator->simulate(['cruiser' => 50], ['light_fighter' => 20], 20);
        self::assertSame(20, $crushing->attackerWins);
        self::assertGreaterThan(0.9, $crushing->defenderLosses);
        self::assertEquals($crushing, $simulator->simulate(['cruiser' => 50], ['light_fighter' => 20], 20));

        // Constat d'équilibrage : l'attaque d'un chasseur léger ne dépasse pas le bouclier d'un croiseur
        $stalemate = $simulator->simulate(['light_fighter' => 200], ['cruiser' => 40], 10, AttackAngle::Rear);
        self::assertSame(10, $stalemate->draws());
        self::assertSame(0.0, $stalemate->defenderLosses);
    }

    public function testCommandsPrintTheirTables(): void
    {
        $application = new Application(self::bootKernel());
        $combat = new CommandTester($application->find('app:balance:combat'));

        self::assertSame(Command::SUCCESS, $combat->execute(['attackers' => 'cruiser:50', 'defenders' => 'light_fighter:20', '--runs' => '5']));
        self::assertStringContainsString('5 (100 %)', $combat->getDisplay());
        self::assertSame(Command::INVALID, $combat->execute(['attackers' => 'croiseurs', 'defenders' => 'light_fighter:20']));
        self::assertStringContainsString('Composition illisible : « croiseurs »', $combat->getDisplay());
        self::assertSame(Command::INVALID, $combat->execute(['attackers' => 'cruiser:1', 'defenders' => 'light_fighter:1', '--angle' => 'dessus']));

        $progression = new CommandTester($application->find('app:balance:progression'));
        self::assertSame(Command::SUCCESS, $progression->execute(['--levels' => '4']));
        self::assertStringContainsString('Mine de métal', $progression->getDisplay());
        self::assertSame(['light_fighter' => 30, 'cruiser' => 2], SimulateCombatCommand::composition('light_fighter:10, cruiser:2, light_fighter:20'));
    }
}
