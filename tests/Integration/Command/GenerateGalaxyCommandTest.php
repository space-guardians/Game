<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\Galaxy;
use App\Entity\StarSystem;
use App\Factory\GalaxyFactory;
use App\Factory\GalaxyShapeTemplateFactory;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyRepository;
use App\Service\Universe\GalaxyGenerator;
use App\Service\Universe\PlanetGenerator;
use App\Service\Universe\SystemPlacer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

final class GenerateGalaxyCommandTest extends KernelTestCase
{
    use Factories;

    public function testGeneratesAndPersistsGalaxy(): void
    {
        $tester = $this->execute(['--seed' => '123', '--systems' => '40', '--name' => 'Voie des Gardiens']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Galaxie n°1 « Voie des Gardiens » générée.', $tester->getDisplay());
        self::assertStringContainsString('--seed=123 --systems=40 --arms=4', $tester->getDisplay());

        $galaxy = $this->reload(1);
        self::assertSame('Voie des Gardiens', $galaxy->getName());
        self::assertCount(40, $galaxy->getSystems());
        self::assertGreaterThan(0, $galaxy->getSystems()->first()->getPlanets()->count());
    }

    public function testUsesNextFreeNumberByDefault(): void
    {
        GalaxyFactory::createOne(['number' => 1]);

        $this->execute(['--systems' => '10'])->assertCommandIsSuccessful();

        self::assertSame('Galaxie 2', $this->reload(2)->getName());
    }

    public function testSameSeedProducesSameGalaxy(): void
    {
        $this->execute(['--number' => '1', '--seed' => '7', '--systems' => '30'])->assertCommandIsSuccessful();
        $this->execute(['--number' => '2', '--seed' => '7', '--systems' => '30'])->assertCommandIsSuccessful();

        self::assertSame($this->positions($this->reload(1)), $this->positions($this->reload(2)));
    }

    public function testRefusesExistingGalaxyNumber(): void
    {
        GalaxyFactory::createOne(['number' => 1]);

        $tester = $this->execute(['--number' => '1', '--systems' => '10']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('La galaxie n°1 existe déjà.', $tester->getDisplay());
    }

    public function testRejectsInvalidShape(): void
    {
        $tester = $this->execute(['--arms' => '0', '--systems' => '10']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame(0, self::getContainer()->get(GalaxyRepository::class)->count([]));
    }

    public function testGeneratesFromShapeTemplate(): void
    {
        $shape = new SpiralGalaxyShape(arms: 2, armTightness: 3.5);
        GalaxyShapeTemplateFactory::createOne(['name' => 'Deux bras', 'arms' => 2, 'armTightness' => 3.5]);

        $tester = $this->execute(['--number' => '1', '--seed' => '9', '--systems' => '30', '--template' => 'Deux bras']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('--template="Deux bras"', $tester->getDisplay());
        $expected = (new GalaxyGenerator(new SystemPlacer(), new PlanetGenerator()))->generate(1, 'Attendue', 9, $shape, 30);
        self::assertSame($this->positions($expected), $this->positions($this->reload(1)));
    }

    public function testRefusesUnknownShapeTemplate(): void
    {
        $tester = $this->execute(['--template' => 'Inexistant', '--systems' => '10']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Gabarit de forme inconnu : « Inexistant ».', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    private function execute(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:galaxy:generate'));
        $tester->execute($options);

        return $tester;
    }

    private function reload(int $number): Galaxy
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $galaxy = self::getContainer()->get(GalaxyRepository::class)->findOneBy(['number' => $number]);
        self::assertInstanceOf(Galaxy::class, $galaxy);

        return $galaxy;
    }

    /**
     * @return list<array{float, float}>
     */
    private function positions(Galaxy $galaxy): array
    {
        return array_values($galaxy->getSystems()->map(
            static fn(StarSystem $s): array => [$s->getPosition()->x, $s->getPosition()->y],
        )->toArray());
    }
}
