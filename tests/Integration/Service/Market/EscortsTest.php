<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Market;

use App\Entity\BattleParticipant;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Planet;
use App\Entity\PlayerQuest;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetStatus;
use App\Enum\Market\PlayerQuestStatus;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Exception\Market\InvalidMarketAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\FleetMovementRepository;
use App\Repository\PlayerQuestRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Market\Escorts;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Quêtes d'escorte (§4.15) : contrat publié avec récompense bloquée, escortes au départ, voyage commun, interception
 * affrontée ensemble, récompense aux escortes si le convoi arrive sans perte.
 */
final class EscortsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-17 10:00:00');
    }

    public function testEscortedConvoyArrivesWithItsEscortAndTheEscortIsPaid(): void
    {
        $poster = $this->empire('Ana');
        $home = $poster->getHomePlanet();
        $colony = $this->colonyOf($poster);
        $convoy = $this->fleet($poster, $home, ['small_cargo' => 2], fuel: false);
        $escorter = $this->empire('Bob');
        $guard = $this->fleet($escorter, $home, ['cruiser' => 3]);

        $quest = $this->escorts()->post($poster, $convoy, $this->step($colony), $this->clock->now()->modify('+1 hour'), new Resources(1000), new Resources(500));
        // Récompense bloquée dès la publication, sur la planète du convoi
        self::assertEqualsWithDelta(9000, $home->getResources()->metal, 1.0);
        self::assertSame(PlayerQuestStatus::Open, $quest->getStatus());
        // Seuls le départ et l'heure sont publics
        self::assertFalse($quest->isKnownTo($escorter));
        self::assertSame([$quest->getId()], array_map(static fn(PlayerQuest $open): ?int => $open->getId(), $this->quests()->findOpenForOthers($escorter)));

        $this->escorts()->accept($quest, $escorter, $guard);
        self::assertTrue($quest->isKnownTo($escorter));

        // Départ à l'heure dite : convoi et escorte en vol, l'escorte portée par le déplacement du convoi
        $this->clock->sleep(3600);
        $this->resolve($quest->getDepartureEvent());
        $quest = $this->reloadQuest($quest);
        self::assertSame(PlayerQuestStatus::Underway, $quest->getStatus());
        $convoyMovement = $this->movements()->findActiveFor($this->reloadFleet($convoy));
        $escortMovement = $this->movements()->findActiveFor($this->reloadFleet($guard));
        self::assertNotNull($convoyMovement);
        self::assertSame($convoyMovement->getId(), $escortMovement?->getCarriedBy()?->getId());
        self::assertEquals($convoyMovement->getArrivesAt(), $escortMovement->getArrivesAt());
        self::assertEquals($convoyMovement->getArrivesAt(), $quest->getArrivesAt());
        self::assertNull($escortMovement->getEvent());
        // Destination secrète : convoi et escorte cachés aux scanners
        $secret = $this->quests()->secretFleetIds();
        self::assertArrayHasKey((int) $convoy->getId(), $secret);
        self::assertArrayHasKey((int) $guard->getId(), $secret);
        $escorterStock = $this->reloadEmpire($escorter)->getHomePlanet()->getResources()->metal;

        // Arrivée : l'escorte arrive avec le convoi, la récompense lui revient
        $this->clock->sleep($convoyMovement->getDurationSeconds());
        $this->resolve($convoyMovement->getEvent());
        $quest = $this->reloadQuest($quest);
        self::assertSame(PlayerQuestStatus::Succeeded, $quest->getStatus());
        $guard = $this->reloadFleet($guard);
        self::assertSame(FleetStatus::Stationed, $guard->getStatus());
        self::assertSame($colony->getId(), $guard->getPlanet()?->getId());
        self::assertNull($this->movements()->findActiveFor($guard));
        self::assertEqualsWithDelta($escorterStock + 1000, $this->reloadEmpire($escorter)->getHomePlanet()->getResources()->metal, 60.0);
        // La cargaison a été livrée par le convoi
        self::assertEqualsWithDelta(100 + 500, $this->reloadPlanet($colony)->getResources()->metal, 60.0);
    }

    public function testGuardsStopTheConvoyAndItsEscortTogetherAndThePosterGetsTheRewardBack(): void
    {
        $poster = $this->empire('Ana');
        $home = $poster->getHomePlanet();
        $colony = $this->colonyOf($poster);
        $convoy = $this->fleet($poster, $home, ['small_cargo' => 2], fuel: false);
        $escorter = $this->empire('Bob');
        $guard = $this->fleet($escorter, $home, ['light_fighter' => 2]);
        // Gardes hostiles au niveau du système de destination : ils interceptent le groupe entier
        $pirate = $this->empire('Pirates');
        $wall = $this->fleet($pirate, $pirate->getHomePlanet(), ['battleship' => 40]);
        $wall->arriveAt(SpaceLocation::of(SpacePosition::system($colony->getSystem())), null);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $quest = $this->escorts()->post($poster, $convoy, $this->step($colony), $this->clock->now()->modify('+1 hour'), new Resources(1000));
        $this->escorts()->accept($quest, $escorter, $guard);
        $this->clock->sleep(3600);
        $this->resolve($quest->getDepartureEvent());
        $convoyMovement = $this->movements()->findActiveFor($this->reloadFleet($convoy));
        \assert(null !== $convoyMovement);
        $posterStock = $this->reloadEmpire($poster)->getHomePlanet()->getResources()->metal;
        $this->clock->sleep($convoyMovement->getDurationSeconds());
        $arrival = $convoyMovement->getEvent();
        // Chaque réveil arrive au worker dans son propre message, avec un gestionnaire d'entités vierge
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->resolve($arrival);

        self::assertSame(PlayerQuestStatus::Failed, $this->reloadQuest($quest)->getStatus());
        // L'escorte a combattu dans le camp du convoi
        $sides = array_map(static fn(BattleParticipant $participant): string => $participant->getEmpire()->getName() . ':' . $participant->getSide()->value, self::getContainer()->get(EntityManagerInterface::class)->getRepository(BattleParticipant::class)->findAll());
        self::assertContains('Bob:' . CombatSide::Attacker->value, $sides);
        self::assertContains('Ana:' . CombatSide::Attacker->value, $sides);
        self::assertEqualsWithDelta($posterStock + 1000, $this->reloadEmpire($poster)->getHomePlanet()->getResources()->metal, 60.0);
    }

    public function testContractRules(): void
    {
        $poster = $this->empire('Ana');
        $home = $poster->getHomePlanet();
        $colony = $this->colonyOf($poster);
        $convoy = $this->fleet($poster, $home, ['small_cargo' => 2], fuel: false);
        $escorter = $this->empire('Bob');
        $elsewhere = $this->fleet($escorter, $escorter->getHomePlanet(), ['cruiser' => 1]);

        // Préavis : les escortes doivent pouvoir venir
        $this->assertRefused(fn() => $this->escorts()->post($poster, $convoy, $this->step($colony), $this->clock->now()->modify('+10 minutes'), new Resources(1000)), 'Le départ se fixe entre 30 minutes et 7 jours à l’avance');
        $quest = $this->escorts()->post($poster, $convoy, $this->step($colony), $this->clock->now()->modify('+1 hour'), new Resources(1000));
        // Un convoi par contrat, une escorte au point de départ
        $this->assertRefused(fn() => $this->escorts()->post($poster, $convoy, $this->step($colony), $this->clock->now()->modify('+2 hours'), new Resources(10)), 'déjà engagée dans un contrat d’escorte');
        $this->assertRefused(fn() => $this->escorts()->accept($quest, $escorter, $elsewhere), 'Une escorte attend stationnée au point de départ');
        $this->assertRefused(fn() => $this->escorts()->accept($quest, $poster, $convoy), 'Vous n’escortez pas votre propre convoi');

        // Annulation avant le départ : récompense rendue, départ annulé
        $this->escorts()->cancel($quest, $poster);
        self::assertSame(PlayerQuestStatus::Cancelled, $quest->getStatus());
        self::assertSame(ScheduledEventStatus::Cancelled, $quest->getDepartureEvent()?->getStatus());
        self::assertEqualsWithDelta(10_000, $home->getResources()->metal, 1.0);
    }

    public function testAnEscortThatLeftTheDepartureStaysBehind(): void
    {
        $poster = $this->empire('Ana');
        $home = $poster->getHomePlanet();
        $convoy = $this->fleet($poster, $home, ['small_cargo' => 2], fuel: false);
        $escorter = $this->empire('Bob');
        $guard = $this->fleet($escorter, $home, ['cruiser' => 1]);
        $quest = $this->escorts()->post($poster, $convoy, $this->step($this->colonyOf($poster)), $this->clock->now()->modify('+1 hour'), new Resources(1000));
        $this->escorts()->accept($quest, $escorter, $guard);
        // L'escorte s'en va avant l'heure
        $guard->arriveAt(SpaceLocation::of(SpacePosition::planet($escorter->getHomePlanet())), $escorter->getHomePlanet());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->clock->sleep(3600);
        $this->resolve($quest->getDepartureEvent());

        $quest = $this->reloadQuest($quest);
        self::assertSame(PlayerQuestStatus::Underway, $quest->getStatus());
        self::assertCount(0, $quest->getEscorts());
        self::assertSame(FleetStatus::Stationed, $this->reloadFleet($guard)->getStatus());
    }

    private function assertRefused(\Closure $action, string $message): void
    {
        try {
            $action();
            self::fail('Refus attendu : ' . $message);
        } catch (InvalidMarketAction $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function step(Planet $planet): MissionStep
    {
        return new MissionStep(SpacePosition::planet($planet), FleetAction::Transport, (string) $planet->getAddress());
    }

    private function empire(string $name): Empire
    {
        $empire = EmpireFactory::createOne(['name' => $name]);
        $empire->getHomePlanet()->storeResources(new Resources(10_000, 10_000, 10_000), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    /** Colonie dans un autre système de la même galaxie */
    private function colonyOf(Empire $empire): Planet
    {
        $home = $empire->getHomePlanet()->getSystem();
        $colony = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 1500, $home->getPosition()->y),
        ])]);
        $colony->assignTo($empire);
        $colony->storeResources(new Resources(100, 100, 100), $this->clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $colony;
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, Planet $at, array $ships, bool $fuel = true): Fleet
    {
        $fleet = new Fleet($empire, 'Flotte ' . $empire->getName(), $at, $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        if ($fuel) {
            $fleet->refuel($fleet->tankCapacity());
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function resolve(?ScheduledEvent $event): void
    {
        self::assertNotNull($event);
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $event->getId());
        $resolved = self::getContainer()->get(EntityManagerInterface::class)->find(ScheduledEvent::class, $event->getId());
        self::assertSame(ScheduledEventStatus::Done, $resolved?->getStatus(), (string) $resolved?->getError());
    }

    private function reloadQuest(PlayerQuest $quest): PlayerQuest
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $found = self::getContainer()->get(PlayerQuestRepository::class)->find($quest->getId());
        \assert($found instanceof PlayerQuest);

        return $found;
    }

    private function reloadFleet(Fleet $fleet): Fleet
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Fleet::class, $fleet->getId());
        \assert($found instanceof Fleet);

        return $found;
    }

    private function reloadEmpire(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }

    private function reloadPlanet(Planet $planet): Planet
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Planet::class, $planet->getId());
        \assert($found instanceof Planet);

        return $found;
    }

    private function escorts(): Escorts
    {
        return self::getContainer()->get(Escorts::class);
    }

    private function quests(): PlayerQuestRepository
    {
        return self::getContainer()->get(PlayerQuestRepository::class);
    }

    private function movements(): FleetMovementRepository
    {
        return self::getContainer()->get(FleetMovementRepository::class);
    }
}
