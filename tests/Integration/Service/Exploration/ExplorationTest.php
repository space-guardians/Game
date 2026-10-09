<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Exploration;

use App\Entity\Empire;
use App\Entity\ExplorationEventInstance;
use App\Entity\Fleet;
use App\Entity\FleetMovement;
use App\Entity\GlobalPosition;
use App\Entity\QuestTemplate;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Entity\StarSystem;
use App\Enum\Exploration\ExplorationEventStatus;
use App\Enum\Fleet\FleetAction;
use App\Enum\Fleet\FleetOrderStatus;
use App\Enum\Fleet\FleetStatus;
use App\Exception\Exploration\InvalidQuestChoice;
use App\Exception\Fleet\InvalidFleetMission;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\QuestOutcomeFactory;
use App\Factory\QuestTemplateFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\BuildingTypeRepository;
use App\Repository\ExplorationEventInstanceRepository;
use App\Repository\FleetMovementRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Exploration\ExplorationExpiryHandler;
use App\Service\Exploration\Explorations;
use App\Service\Fleet\FleetDispatch;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Exploration (§4.6.4) : apparition d'une quête à l'arrivée, résolution automatique ou choix du joueur, effets,
 * chaînage, échéance.
 */
final class ExplorationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-09 10:00:00');
        // Contenu de départ écarté : chaque test pose ses propres quêtes
        $this->entityManager()->createQuery('UPDATE ' . QuestTemplate::class . ' q SET q.active = false')->execute();
    }

    public function testExplorationCannotTargetAPlanet(): void
    {
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);
        $planet = PlanetFactory::createOne(['system' => $this->targetSystem($empire)]);

        $this->expectExceptionObject(new InvalidFleetMission('Ordre 1 : « Exploration » ne peut pas viser une planète : visez un système (sans position).'));

        $this->dispatch($fleet, [new MissionStep(SpacePosition::planet($planet), FleetAction::Explore, 'planète')]);
    }

    public function testAutomaticQuestAppliesItsOutcomeAndTheFleetCarriesOn(): void
    {
        QuestTemplateFactory::createOne([
            'name' => 'Épave',
            'outcomes' => [QuestOutcomeFactory::new(['label' => 'Butin', 'text' => 'Des conteneurs intacts.', 'metal' => 3000, 'deuterium' => -100, 'shipLossPercent' => 10])],
        ]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1, 'light_fighter' => 10]);

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        $fleet = $this->reload($fleet);
        $event = $this->events($empire)[0];
        self::assertSame(ExplorationEventStatus::Resolved, $event->getStatus());
        self::assertSame('Épave', $event->getTitle());
        self::assertSame('Butin', $event->getOutcomeLabel());
        self::assertStringContainsString('Des conteneurs intacts.', (string) $event->getReport());
        self::assertStringContainsString('Vaisseaux perdus : 1 × Chasseur léger.', (string) $event->getReport());
        // 1 chasseur sur 10 perdu ; cargaison : +3 000 de métal (cargo libre suffisant), pas de deutérium à perdre
        self::assertSame(['small_cargo' => 1, 'light_fighter' => 9], $fleet->shipCounts());
        self::assertSame(3000.0, $fleet->getCargo()->metal);
        // L'exploration est faite ; la flotte enchaîne aussitôt l'ordre suivant
        self::assertSame(FleetOrderStatus::Done, $fleet->getOrders()[0]?->getStatus());
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        self::assertSame(2, self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet)?->getOrder()->getRank());
    }

    public function testNothingHappensWhenNoQuestIsEligible(): void
    {
        $astrophysics = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('astrophysics');
        QuestTemplateFactory::createOne(['requiredTechnology' => $astrophysics, 'requiredTechnologyLevel' => 5]);
        QuestTemplateFactory::createOne(['active' => false]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 10]);

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        self::assertSame([], $this->events($empire));
        self::assertSame(FleetStatus::InFlight, $this->reload($fleet)->getStatus());
    }

    public function testChoiceQuestHoldsTheFleetUntilThePlayerDecides(): void
    {
        QuestTemplateFactory::new()->choice()->create([
            'name' => 'Signal',
            'outcomes' => [
                QuestOutcomeFactory::new(['label' => 'Enquêter', 'crystal' => 200]),
                QuestOutcomeFactory::new(['label' => 'Ignorer']),
            ],
        ]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        $fleet = $this->reload($fleet);
        $event = $this->events($fleet->getEmpire())[0];
        self::assertSame(ExplorationEventStatus::AwaitingChoice, $event->getStatus());
        self::assertEquals($this->clock->now()->modify('+1 day'), $event->getExpiresAt());
        // La flotte attend sur place ; son retour reste à venir
        self::assertSame(FleetStatus::Stationed, $fleet->getStatus());
        self::assertSame(FleetOrderStatus::Pending, $fleet->getOrders()[1]?->getStatus());
        self::assertNull(self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet));
        self::assertSame($event, self::getContainer()->get(ExplorationEventInstanceRepository::class)->findAwaitingByFleet($fleet->getEmpire())[$fleet->getId()] ?? null);

        $investigate = $event->getTemplate()?->getOutcomes()->first();
        \assert(false !== $investigate && null !== $investigate);
        self::getContainer()->get(Explorations::class)->choose($event, $investigate, $fleet->getEmpire());

        self::assertSame(ExplorationEventStatus::Resolved, $event->getStatus());
        self::assertSame(200.0, $fleet->getCargo()->crystal);
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        self::assertSame(FleetOrderStatus::InProgress, $fleet->getOrders()[1]->getStatus());

        $this->expectExceptionObject(new InvalidQuestChoice('Cet événement est déjà réglé.'));
        self::getContainer()->get(Explorations::class)->choose($event, $investigate, $fleet->getEmpire());
    }

    public function testOutcomeChainsTheNextQuest(): void
    {
        $relief = QuestTemplateFactory::new()->choice()->create(['name' => 'Convoi de secours', 'chance' => 0]);
        QuestTemplateFactory::createOne([
            'name' => 'Détresse',
            'outcomes' => [QuestOutcomeFactory::new(['label' => 'Enquêter', 'nextQuest' => $relief])],
        ]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        [$next, $first] = $this->events($this->reload($fleet)->getEmpire());
        self::assertSame('Détresse', $first->getTitle());
        self::assertSame(ExplorationEventStatus::Resolved, $first->getStatus());
        self::assertStringContainsString('Suite : « Convoi de secours ».', (string) $first->getReport());
        self::assertSame('Convoi de secours', $next->getTitle());
        self::assertSame(ExplorationEventStatus::AwaitingChoice, $next->getStatus());
        self::assertSame($first, $next->getPrevious());
    }

    public function testChainStopsWhenTheFleetLacksWhatTheNextQuestRequires(): void
    {
        $relief = QuestTemplateFactory::new()->choice()->create(['name' => 'Convoi de secours', 'chance' => 0, 'requiredCargoDeuterium' => 1000]);
        QuestTemplateFactory::createOne(['outcomes' => [QuestOutcomeFactory::new(['nextQuest' => $relief])]]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        $events = $this->events($this->reload($fleet)->getEmpire());
        self::assertCount(1, $events);
        self::assertStringContainsString('La suite (« Convoi de secours ») demandait : en cargaison : 1000 de deutérium. La piste s’arrête là.', (string) $events[0]->getReport());
        self::assertSame(FleetStatus::InFlight, $this->reload($fleet)->getStatus());
    }

    public function testUnansweredQuestExpiresAndTheFleetResumesItsOrders(): void
    {
        QuestTemplateFactory::new()->choice(30)->create();
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);
        $this->arrive($fleet, $this->exploreThenReturn($empire));

        $this->clock->sleep(30 * 60);
        $expiry = $this->entityManager()->getRepository(ScheduledEvent::class)->findOneBy(['type' => ExplorationExpiryHandler::TYPE]);
        \assert($expiry instanceof ScheduledEvent);
        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $expiry->getId()));

        $fleet = $this->reload($fleet);
        $event = $this->events($fleet->getEmpire())[0];
        self::assertSame(ExplorationEventStatus::Expired, $event->getStatus());
        self::assertSame(FleetStatus::InFlight, $fleet->getStatus());
        self::assertSame(2, self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($fleet)?->getOrder()->getRank());
    }

    public function testChoosingAfterTheFleetLeftIsRefused(): void
    {
        QuestTemplateFactory::new()->choice()->create();
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);
        $this->arrive($fleet, $this->exploreThenReturn($empire));
        $fleet = $this->reload($fleet);
        // Le joueur renvoie la flotte ailleurs sans décider
        $this->dispatch($fleet, [new MissionStep(SpacePosition::planet($fleet->getEmpire()->getHomePlanet()), FleetAction::Station, 'retour')], 0);
        $event = $this->events($fleet->getEmpire())[0];
        $outcome = $event->getTemplate()?->getOutcomes()->first();
        \assert(false !== $outcome && null !== $outcome);

        try {
            self::getContainer()->get(Explorations::class)->choose($event, $outcome, $fleet->getEmpire());
            self::fail('Le choix aurait dû être refusé.');
        } catch (InvalidQuestChoice $exception) {
            self::assertSame('La flotte a quitté les lieux : l’occasion est perdue.', $exception->getMessage());
        }
        self::assertSame(ExplorationEventStatus::Expired, $event->getStatus());
    }

    public function testAnotherEmpireCannotDecide(): void
    {
        QuestTemplateFactory::new()->choice()->create();
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['small_cargo' => 1]);
        $this->arrive($fleet, $this->exploreThenReturn($empire));
        $event = $this->events($this->reload($fleet)->getEmpire())[0];
        $outcome = $event->getTemplate()?->getOutcomes()->first();
        \assert(false !== $outcome && null !== $outcome);

        $this->expectExceptionObject(new InvalidQuestChoice('Cet événement n’est pas le vôtre.'));

        self::getContainer()->get(Explorations::class)->choose($event, $outcome, EmpireFactory::createOne(['foundedAt' => $this->clock->now()]));
    }

    public function testFleetWipedOutInExplorationDisappears(): void
    {
        QuestTemplateFactory::createOne(['outcomes' => [QuestOutcomeFactory::new(['text' => 'Un trou noir.', 'shipLossPercent' => 100])]]);
        $empire = $this->empire();
        $fleet = $this->fleet($empire, ['light_fighter' => 2]);
        $fleetId = $fleet->getId();

        $this->arrive($fleet, $this->exploreThenReturn($empire));

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Fleet::class, $fleetId));
        $events = $this->entityManager()->getRepository(ExplorationEventInstance::class)->findAll();
        self::assertCount(1, $events);
        self::assertNull($events[0]->getFleet());
        self::assertStringContainsString('La flotte est perdue.', (string) $events[0]->getReport());
    }

    /**
     * Exploration d'un système voisin, puis retour à la planète mère.
     *
     * @return list<MissionStep>
     */
    private function exploreThenReturn(Empire $empire): array
    {
        return [
            new MissionStep(SpacePosition::system($this->targetSystem($empire)), FleetAction::Explore, 'système voisin'),
            new MissionStep(SpacePosition::planet($empire->getHomePlanet()), FleetAction::Station, 'retour'),
        ];
    }

    /** @param list<MissionStep> $steps */
    private function arrive(Fleet $fleet, array $steps): void
    {
        $movement = $this->dispatch($fleet, $steps);
        $this->clock->sleep($movement->getDurationSeconds());
        self::assertSame(1, self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $movement->getEvent()?->getId()));
    }

    /**
     * @param list<MissionStep> $steps
     * @param float|null        $fuel  plein des réservoirs par défaut
     */
    private function dispatch(Fleet $fleet, array $steps, ?float $fuel = null): FleetMovement
    {
        return self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, $steps, 100, new Resources(), $fuel ?? $fleet->tankCapacity() - $fleet->getFuel());
    }

    /** @return list<ExplorationEventInstance> plus récents d'abord */
    private function events(Empire $empire): array
    {
        return self::getContainer()->get(ExplorationEventInstanceRepository::class)->findBy(['empire' => $empire], ['id' => 'DESC']);
    }

    private function targetSystem(Empire $empire): StarSystem
    {
        $home = $empire->getHomePlanet()->getSystem();

        return StarSystemFactory::createOne([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 3000, $home->getPosition()->y),
        ]);
    }

    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $planet = $empire->getHomePlanet();
        foreach (['metal_storage', 'crystal_storage', 'deuterium_storage'] as $storage) {
            $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($storage);
            \assert(null !== $type);
            $planet->setBuildingLevel($type, 10);
        }
        $planet->storeResources(new Resources(50_000, 50_000, 50_000), $this->clock->now());
        $this->entityManager()->flush();

        return $empire;
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Éclaireurs', $empire->getHomePlanet(), $this->clock->now());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();

        return $fleet;
    }

    private function reload(Fleet $fleet): Fleet
    {
        $this->entityManager()->clear();
        $fleet = $this->entityManager()->find(Fleet::class, $fleet->getId());
        \assert($fleet instanceof Fleet);

        return $fleet;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
