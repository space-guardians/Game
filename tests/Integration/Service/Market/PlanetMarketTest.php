<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Market;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Merchant;
use App\Entity\Planet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Enum\Market\PlanetListingStatus;
use App\Exception\Market\InvalidMarketAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Combat\Pillage;
use App\Service\Economy\PlanetResources;
use App\Service\Market\PlanetMarket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Vente et échange de planètes (§4.15) : gel (ni production ni pillage), achat conditionné à un emplacement de
 * colonisation libre, échange planète contre planète.
 */
final class PlanetMarketTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 10:00:00');
    }

    public function testAListedPlanetIsFrozenThenSoldToABuyerWithAFreeColonySlot(): void
    {
        $ana = $this->empire('Ana');
        $colony = $this->colonyOf($ana);
        $ana->switchTo($colony);
        $merchant = $this->merchant($colony);
        $bob = $this->empire('Bob', astrophysics: 0);

        $listing = $this->market()->list($ana, $colony, $merchant, new Resources(1000.0));

        // Gelée : plus aucune production
        self::assertTrue($colony->isFrozen());
        $stock = $colony->getResources();
        $this->clock->sleep(7200);
        self::assertEquals($stock, self::getContainer()->get(PlanetResources::class)->snapshot($colony)->amounts);

        // Sans emplacement de colonisation libre, pas d'achat
        try {
            $this->market()->buy($listing, $bob);
            self::fail('Planète achetée sans emplacement de colonisation libre.');
        } catch (InvalidMarketAction $refusal) {
            self::assertStringStartsWith('Achat impossible : limite de colonies atteinte', $refusal->getMessage());
        }
        $this->research($bob, 'astrophysics', 1);
        $bobStock = $bob->getHomePlanet()->getResources();

        $this->market()->buy($listing, $bob);

        self::assertSame(PlanetListingStatus::Completed, $listing->getStatus());
        self::assertSame($bob, $colony->getOwner());
        self::assertFalse($colony->isFrozen());
        self::assertSame($ana->getHomePlanet(), $ana->getActivePlanet());
        self::assertEqualsWithDelta($bobStock->metal - 1000.0, $bob->getHomePlanet()->getResources()->metal, 1.0);
        self::assertEquals(new Resources(1000.0), self::getContainer()->get(MerchantHoldingRepository::class)->forEmpire($merchant, $ana)->getResources());
    }

    public function testPlanetsAreSwappedOnlyOnceTheSellerAccepts(): void
    {
        $ana = $this->empire('Ana');
        $bob = $this->empire('Bob');
        [$wanted, $offered] = [$this->colonyOf($ana), $this->colonyOf($bob)];
        $listing = $this->market()->list($ana, $wanted);
        self::assertTrue($listing->isSwap());

        $this->market()->offer($listing, $bob, $offered);
        self::assertTrue($offered->isFrozen());
        try {
            $this->market()->cancel($listing, $ana);
            self::fail('Annonce retirée malgré une proposition en attente.');
        } catch (InvalidMarketAction) {
        }

        // Refusée : la planète proposée est dégelée, l'annonce rouverte
        $this->market()->dropOffer($listing, $ana);
        self::assertFalse($offered->isFrozen());
        self::assertSame(PlanetListingStatus::Open, $listing->getStatus());

        $this->market()->offer($listing, $bob, $offered);
        $this->market()->acceptOffer($listing, $ana);

        self::assertSame([$bob, $ana], [$wanted->getOwner(), $offered->getOwner()]);
        self::assertFalse($wanted->isFrozen() || $offered->isFrozen());
        self::assertSame(PlanetListingStatus::Completed, $listing->getStatus());
    }

    public function testTradingRules(): void
    {
        $ana = $this->empire('Ana');
        $colony = $this->colonyOf($ana);
        $bob = $this->empire('Bob');
        $merchant = $this->merchant($colony);

        foreach ([
            'La planète mère ne se vend pas.' => fn() => $this->market()->list($ana, $ana->getHomePlanet()),
            \sprintf('%s n’appartient pas à votre empire.', $colony) => fn() => $this->market()->list($bob, $colony),
            'Fixez un prix et un marchand pour une vente, ou ni l’un ni l’autre pour un échange.' => fn() => $this->market()->list($ana, $colony, $merchant),
        ] as $expected => $attempt) {
            try {
                $attempt();
                self::fail('Mise sur le marché acceptée à tort.');
            } catch (InvalidMarketAction $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }

        $listing = $this->market()->list($ana, $colony, $merchant, new Resources(10.0));
        foreach ([
            \sprintf('%s est déjà sur le marché.', $colony) => fn() => $this->market()->list($ana, $colony),
            'Vous ne pouvez pas acheter votre propre planète.' => fn() => $this->market()->buy($listing, $ana),
            'Cette planète n’attend pas de proposition d’échange.' => fn() => $this->market()->offer($listing, $bob, $this->colonyOf($bob)),
        ] as $expected => $attempt) {
            try {
                $attempt();
                self::fail('Action de marché acceptée à tort.');
            } catch (InvalidMarketAction $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }

        // Retirée : la planète est dégelée
        $this->market()->cancel($listing, $ana);
        self::assertFalse($colony->isFrozen());
        self::assertSame(PlanetListingStatus::Cancelled, $listing->getStatus());
    }

    public function testAFrozenPlanetCannotBePillaged(): void
    {
        $ana = $this->empire('Ana');
        $colony = $this->colonyOf($ana);
        $raider = $this->empire('Raider');
        $fleet = new Fleet($raider, 'Pillards', $raider->getHomePlanet(), $this->clock->now());
        $cargo = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('small_cargo');
        \assert($cargo instanceof ShipType);
        $fleet->addShips($cargo, 10);
        $battle = new Battle(SpaceLocation::of(SpacePosition::planet($colony)), 'colonie', $this->clock->now(), $this->clock->now());
        $battle->resolve(CombatSide::Attacker, 1, [], $this->clock->now());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->persist($battle);
        $entityManager->flush();
        $this->market()->list($ana, $colony);

        self::assertTrue(self::getContainer()->get(Pillage::class)->plunder($battle, $raider, [$fleet], $this->clock->now())->isZero());
    }

    private function empire(string $name, int $astrophysics = 2): Empire
    {
        $empire = EmpireFactory::createOne(['name' => $name]);
        $empire->getHomePlanet()->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
        $this->research($empire, 'astrophysics', $astrophysics);

        return $empire;
    }

    private function research(Empire $empire, string $code, int $level): void
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        \assert(null !== $technology);
        $empire->setResearchLevel($technology, $level);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** Colonie productive (mine de métal) dans la galaxie de l'empire */
    private function colonyOf(Empire $empire): Planet
    {
        $colony = PlanetFactory::createOne();
        $colony->assignTo($empire);
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);
        $colony->setBuildingLevel($mine, 10);
        $colony->storeResources(new Resources(500, 500, 500), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    private function merchant(Planet $near): Merchant
    {
        $merchant = new Merchant($near->getSystem(), 'Comptoir', 3.0, -2.0);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($merchant);
        $entityManager->flush();

        return $merchant;
    }

    private function market(): PlanetMarket
    {
        return self::getContainer()->get(PlanetMarket::class);
    }
}
