<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Market;

use App\Entity\Galaxy;
use App\Entity\Merchant;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\GalaxyRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\MerchantRepository;
use App\Service\Market\MerchantRules;
use App\Service\Market\Merchants;
use App\Service\Universe\GalaxyCreator;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Marchands (§4.15) : placés avec la galaxie, de façon reproductible, et après coup pour une galaxie qui n'en a pas ;
 * stockage par empire bénéficiaire.
 */
final class MerchantsTest extends KernelTestCase
{
    use Factories;

    public function testAGeneratedGalaxyGetsItsMerchantsReproducibly(): void
    {
        $created = self::getContainer()->get(GalaxyCreator::class)->create(7, 'Marchande', 42, new SpiralGalaxyShape(), 60);

        $merchants = $this->repository()->findInGalaxy($created->galaxy);
        self::assertSame(6, $created->merchants);
        self::assertCount(6, $merchants);
        self::assertCount(6, array_unique(array_map(static fn(Merchant $merchant): int => (int) $merchant->getSystem()->getId(), $merchants)));
        foreach ($merchants as $merchant) {
            self::assertEqualsWithDelta(MerchantRules::STATION_RADIUS, hypot($merchant->getLocalX(), $merchant->getLocalY()), 0.01);
            self::assertMatchesRegularExpression('/^(Comptoir|Relais marchand|Bazar orbital|Entrepôt franc) 7-\d+$/', $merchant->getName());
        }

        // Même graine, même placement
        $again = self::getContainer()->get(GalaxyCreator::class)->create(8, 'Jumelle', 42, new SpiralGalaxyShape(), 60);
        $layout = static fn(Galaxy $galaxy, MerchantRepository $repository): array => array_map(static fn(Merchant $merchant): array => [$merchant->getSystem()->getNumber(), $merchant->getLocalX(), $merchant->getLocalY()], $repository->findInGalaxy($galaxy));
        self::assertSame($layout($created->galaxy, $this->repository()), $layout($again->galaxy, $this->repository()));

        // Une seule fois par galaxie
        $this->expectException(\LogicException::class);
        self::getContainer()->get(Merchants::class)->place($created->galaxy, new Randomizer(new Mt19937(1)));
    }

    public function testCommandPlacesMerchantsInAnOlderGalaxy(): void
    {
        $created = self::getContainer()->get(GalaxyCreator::class)->create(9, 'Ancienne', 3, new SpiralGalaxyShape(), 30);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($this->repository()->findInGalaxy($created->galaxy) as $merchant) {
            $entityManager->remove($merchant);
        }
        $entityManager->flush();

        $tester = $this->command();
        $tester->execute(['galaxy' => '9']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('3 marchand(s) placé(s) dans la galaxie n°9.', $tester->getDisplay());
        $galaxy = self::getContainer()->get(GalaxyRepository::class)->findOneBy(['number' => 9]);
        \assert(null !== $galaxy);
        self::assertSame(3, $this->repository()->countInGalaxy($galaxy));

        $tester->execute(['galaxy' => '9']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('a déjà ses marchands', $tester->getDisplay());
        $tester->execute(['galaxy' => '99']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testHoldingsAreKeptPerBeneficiary(): void
    {
        $created = self::getContainer()->get(GalaxyCreator::class)->create(10, 'Comptoirs', 5, new SpiralGalaxyShape(), 10);
        $merchant = $this->repository()->findInGalaxy($created->galaxy)[0];
        [$ana, $bob] = [EmpireFactory::createOne(), EmpireFactory::createOne()];
        $holdings = self::getContainer()->get(MerchantHoldingRepository::class);

        $holdings->forEmpire($merchant, $ana)->credit(new Resources(500.0));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertEquals(new Resources(500.0), $holdings->forEmpire($merchant, $ana)->getResources());
        self::assertTrue($holdings->forEmpire($merchant, $bob)->isEmpty());
    }

    private function command(): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find('app:merchant:place'));
    }

    private function repository(): MerchantRepository
    {
        return self::getContainer()->get(MerchantRepository::class);
    }
}
