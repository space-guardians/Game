<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Market;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Galaxy;
use App\Entity\GlobalPosition;
use App\Entity\MarketListing;
use App\Entity\Merchant;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Market\MarketListingStatus;
use App\Exception\Fleet\InvalidFleetMission;
use App\Exception\Market\InvalidMarketAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetMovementRepository;
use App\Repository\MerchantHoldingRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Fleet\FleetDispatch;
use App\Service\Market\Market;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Vente et achat de ressources (§4.15) : dépôt chez le marchand par les deux parties, validation à la double
 * livraison, retrait limité au bénéficiaire.
 */
final class MarketTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
    }

    public function testBothPartiesDeliverByFleetThenEachCollectsTheirShare(): void
    {
        $ana = $this->empire('Ana');
        $merchant = $this->merchantNear($ana);
        $bob = $this->empire('Bob', $ana->getHomePlanet()->getSystem()->getGalaxy());
        $listing = $this->market()->post($ana, $merchant, new Resources(1000.0), new Resources(0.0, 500.0));
        $this->market()->accept($listing, $bob);

        // Le vendeur livre : la transaction attend encore l'acheteur
        $delivery = $this->travel($this->fleet($ana), [FleetAction::MarketDeliver], new Resources(1000.0));
        self::assertNull($delivery->getOrders()[0]?->getFailure());
        self::assertTrue($this->listing($listing)->isDeliveredBy($this->reloadEmpire($ana)));
        self::assertSame(MarketListingStatus::Accepted, $this->listing($listing)->getStatus());

        // L'acheteur livre puis, dans la même mission, retire ce qui lui revient
        $fleet = $this->travel($this->fleet($this->reloadEmpire($bob)), [FleetAction::MarketDeliver, FleetAction::MarketCollect], new Resources(0.0, 500.0));

        self::assertSame(MarketListingStatus::Completed, $this->listing($listing)->getStatus());
        self::assertSame([FleetOrderStatus::Done, FleetOrderStatus::Done], array_map(static fn($order): FleetOrderStatus => $order->getStatus(), $fleet->getOrders()->toArray()));
        self::assertEquals(new Resources(1000.0), $fleet->getCargo());
        $holdings = self::getContainer()->get(MerchantHoldingRepository::class);
        self::assertTrue($holdings->forEmpire($this->reloadMerchant($merchant), $this->reloadEmpire($bob))->isEmpty());
        self::assertEquals(new Resources(0.0, 500.0), $holdings->forEmpire($this->reloadMerchant($merchant), $this->reloadEmpire($ana))->getResources());
    }

    public function testDeliveryCoversOnlyWhatTheCargoAffordsAndCollectionOnlyWhatIsHeldForYou(): void
    {
        $ana = $this->empire('Ana');
        $merchant = $this->merchantNear($ana);
        $first = $this->market()->post($ana, $merchant, new Resources(800.0), new Resources(1.0));
        $second = $this->market()->post($ana, $merchant, new Resources(0.0, 300.0), new Resources(1.0));
        $fleet = $this->fleet($ana);
        $fleet->load(new Resources(500.0, 300.0));
        $eve = $this->fleet($this->empire('Eve'));

        // La cargaison ne couvre pas la première annonce : seule la seconde est réglée
        self::assertNull($this->market()->deliver($fleet, $this->station($merchant), $this->clock->now()));
        self::assertFalse($first->isDeliveredBy($ana));
        self::assertTrue($second->isDeliveredBy($ana));
        self::assertEquals(new Resources(500.0), $fleet->getCargo());
        self::assertSame('Aucune livraison n’est attendue de vous chez ' . $merchant->getName() . ' : la cargaison reste à bord.', $this->market()->deliver($eve, $this->station($merchant), $this->clock->now()));

        // Retirée, la seconde annonce rend au vendeur ce qu'il avait livré, et lui seul peut le reprendre
        $this->market()->cancel($second, $ana);
        self::assertSame(\sprintf('%s ne garde aucune ressource pour vous.', $merchant->getName()), $this->market()->collect($eve, $this->station($merchant)));
        $fleet->unload();
        self::assertNull($this->market()->collect($fleet, $this->station($merchant)));
        self::assertEquals(new Resources(0.0, 300.0), $fleet->getCargo());
    }

    public function testCollectionIsLimitedByFreeCargoSpace(): void
    {
        $ana = $this->empire('Ana');
        $merchant = $this->merchantNear($ana);
        $holdings = self::getContainer()->get(MerchantHoldingRepository::class);
        $fleet = $this->fleet($ana);
        $holdings->forEmpire($merchant, $ana)->credit(new Resources($fleet->cargo() * 1.5, $fleet->cargo() * 0.5));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertNull($this->market()->collect($fleet, $this->station($merchant)));

        // Soutes remplies au prorata (3 pour 1), le reste attend chez le marchand
        $cargo = $fleet->getCargo();
        self::assertEqualsWithDelta($fleet->cargo(), $cargo->metal + $cargo->crystal, 2.0);
        self::assertEqualsWithDelta(3.0, $cargo->metal / $cargo->crystal, 0.01);
        self::assertSame('Soutes pleines : rien n’a pu être retiré.', $this->market()->collect($fleet, $this->station($merchant)));
    }

    public function testMarketRules(): void
    {
        $ana = $this->empire('Ana');
        $merchant = $this->merchantNear($ana);
        $listing = $this->market()->post($ana, $merchant, new Resources(10.0), new Resources(5.0));

        foreach ([
            'Indiquez les ressources proposées et la contrepartie demandée.' => fn() => $this->market()->post($ana, $merchant, new Resources(10.0), Resources::zero()),
            'Vous ne pouvez pas acheter votre propre annonce.' => fn() => $this->market()->accept($listing, $ana),
            'Seul le vendeur retire son annonce.' => fn() => $this->market()->cancel($listing, $this->empire('Bob')),
        ] as $expected => $attempt) {
            try {
                $attempt();
                self::fail('Action de marché acceptée à tort.');
            } catch (InvalidMarketAction $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }
        $this->market()->accept($listing, $this->empire('Cid'));
        $this->expectExceptionObject(new InvalidMarketAction('Un acheteur s’est déjà engagé : l’annonce ne peut plus être retirée.'));
        $this->market()->cancel($listing, $ana);
    }

    public function testMarketMissionsMustTargetAMerchantStation(): void
    {
        $ana = $this->empire('Ana');
        $merchant = $this->merchantNear($ana);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : « Livraison au marchand » vise la station d’un marchand.'));
        self::getContainer()->get(FleetDispatch::class)->dispatch($this->fleet($ana), [new MissionStep(SpacePosition::system($merchant->getSystem()), FleetAction::MarketDeliver, 'système')], 100, new Resources());
    }

    /**
     * Envoie la flotte de la planète mère vers le marchand le plus proche pour une suite d'actions, et résout chaque
     * arrivée.
     *
     * @param list<FleetAction> $actions
     */
    private function travel(Fleet $fleet, array $actions, Resources $cargo): Fleet
    {
        $merchant = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Merchant::class)->findOneBy([]);
        \assert(null !== $merchant);
        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, array_map(static fn(FleetAction $action): MissionStep => new MissionStep($merchant->position(), $action, $merchant->getName()), $actions), 100, $cargo);
        while (null !== $movement) {
            $this->clock->sleep($movement->getDurationSeconds());
            self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId()));
            $movement = self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet);
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $fleet = $entityManager->find(Fleet::class, $fleet->getId());
        \assert($fleet instanceof Fleet);

        return $fleet;
    }

    private function empire(string $name, ?Galaxy $galaxy = null): Empire
    {
        $attributes = ['name' => $name];
        if (null !== $galaxy) {
            $attributes['homePlanet'] = PlanetFactory::new(['system' => StarSystemFactory::new(['galaxy' => $galaxy, 'position' => new GlobalPosition(-800.0, 300.0)])]);
        }
        $empire = EmpireFactory::createOne($attributes);
        $empire->getHomePlanet()->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    /** Marchand dans un autre système de la galaxie de l'empire */
    private function merchantNear(Empire $empire): Merchant
    {
        $home = $empire->getHomePlanet()->getSystem();
        $system = StarSystemFactory::createOne(['galaxy' => $home->getGalaxy(), 'position' => new GlobalPosition($home->getPosition()->x + 1000, $home->getPosition()->y)]);
        $merchant = new Merchant($system, 'Comptoir', 3.0, -2.0);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($merchant);
        $entityManager->flush();

        return $merchant;
    }

    private function station(Merchant $merchant): SpaceLocation
    {
        return SpaceLocation::of($merchant->position());
    }

    private function fleet(Empire $empire): Fleet
    {
        $fleet = new Fleet($empire, 'Convoi', $empire->getHomePlanet(), $this->clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('small_cargo');
        \assert($type instanceof ShipType);
        $fleet->addShips($type, 5);
        $fleet->refuel($fleet->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function listing(MarketListing $listing): MarketListing
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(MarketListing::class, $listing->getId());
        \assert($found instanceof MarketListing);

        return $found;
    }

    private function reloadMerchant(Merchant $merchant): Merchant
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Merchant::class, $merchant->getId());
        \assert($found instanceof Merchant);

        return $found;
    }

    private function reloadEmpire(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }

    private function market(): Market
    {
        return self::getContainer()->get(Market::class);
    }
}
