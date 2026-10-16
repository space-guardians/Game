<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ScheduledEvent;
use App\Entity\ShipType;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Enum\Messaging\MessageCategory;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\ConversationRepository;
use App\Repository\EmpireBlueprintRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\BattleResolutionHandler;
use App\Service\Fleet\FleetDispatch;
use App\Service\Messaging\GameReports;
use App\Service\Pve\NpcCivilisations;
use App\Service\Pve\NpcRewards;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Butin PvE (§4.14) : vaincre une civilisation sur sa planète rapporte la part de pillage de son profil et sa
 * récompense de victoire.
 */
final class NpcRewardsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 17:00:00');
    }

    public function testDefeatingACivilisationPaysItsLootShareAndVictoryReward(): void
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $system = $player->getHomePlanet()->getSystem();
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider');
        \assert(null !== $profile);
        // Part de pillage du profil, distincte de la moitié appliquée aux joueurs
        $profile->setLootPercent(30);
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new(['galaxy' => $system->getGalaxy(), 'position' => new GlobalPosition($system->getPosition()->x + 600, $system->getPosition()->y)])]);
        self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Pillards', $planet);
        $home = $player->getHomePlanet();
        $home->storeResources(new Resources(), $this->clock->now());

        // Une flotte écrasante, avec de quoi emporter le butin
        $raid = new Fleet($player, 'Expédition punitive', $home, $this->clock->now());
        foreach (['cruiser' => 300, 'large_cargo' => 50] as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $raid->addShips($type, $quantity);
        }
        $raid->refuel($raid->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($raid);
        $entityManager->flush();

        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($raid, [new MissionStep(SpacePosition::planet($planet), FleetAction::JoinBattle, 'Pillards')], 100, new Resources());
        $this->clock->sleep($movement->getDurationSeconds());
        $resolver = self::getContainer()->get(ScheduledEventResolver::class);
        $resolver->resolve((int) $movement->getEvent()?->getId());
        $pending = $entityManager->getRepository(ScheduledEvent::class)->findOneBy(['type' => BattleResolutionHandler::TYPE, 'status' => ScheduledEventStatus::Pending]);
        if ($pending instanceof ScheduledEvent) {
            $resolver->resolve((int) $pending->getId());
        }

        $battle = $entityManager->getRepository(Battle::class)->findOneBy([]);
        \assert($battle instanceof Battle);
        self::assertSame(CombatSide::Attacker, $battle->getWinner());
        $entityManager->clear();
        $raid = $entityManager->find(Fleet::class, $raid->getId());
        $player = $entityManager->find(Empire::class, $player->getId());
        \assert($raid instanceof Fleet && $player instanceof Empire);

        // Pillage : 30 % du stock de la civilisation (production de son trajet comprise : quelques unités de plus)
        self::assertEqualsWithDelta(NpcCivilisations::STARTING_STOCK * 0.3, $raid->getCargo()->metal, 50.0);
        // Récompense de victoire versée sur la planète mère (vide au départ, plus sa production pendant le trajet)
        self::assertEqualsWithDelta(5000.0, $player->getHomePlanet()->getResources()->metal, 50.0);
        self::getContainer()->get(GameReports::class)->send();
        $subjects = array_map(static fn($place): ?string => $place->getConversation()->getSubject(), self::getContainer()->get(ConversationRepository::class)->findPlacesOf($player, category: MessageCategory::Combat));
        self::assertContains('Victoire sur Pillards', $subjects);
    }

    public function testBossGivesAntimatterAndItsBlueprintOnlyOnce(): void
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $system = $player->getHomePlanet()->getSystem();
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $profile);
        // Contenu de départ (§4.14) : antimatière et plan du croiseur d'antimatière
        self::assertSame(150, $profile->getVictoryAntimatter());
        self::assertSame('antimatter_cruiser', $profile->getVictoryBlueprint()?->getCode());
        $planet = PlanetFactory::createOne(['system' => StarSystemFactory::new(['galaxy' => $system->getGalaxy(), 'position' => new GlobalPosition($system->getPosition()->x + 600, $system->getPosition()->y)])]);
        self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Gardiens', $planet);
        $rewards = self::getContainer()->get(NpcRewards::class);

        // Deux victoires, chacune résolue (et enregistrée) avec sa bataille
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $rewards->victory($planet, $player, $this->clock->now());
        $entityManager->flush();
        $rewards->victory($planet, $player, $this->clock->now());
        $entityManager->flush();

        self::assertSame(300.0, $player->getHomePlanet()->getAntimatter());
        $blueprints = self::getContainer()->get(EmpireBlueprintRepository::class);
        self::assertCount(1, $blueprints->findOwnedBy($player));
        self::assertTrue($blueprints->has($player, $profile->getVictoryBlueprint()));
        $reports = self::getContainer()->get(GameReports::class);
        $reports->send();
        $places = self::getContainer()->get(ConversationRepository::class)->findPlacesOf($player, category: MessageCategory::Combat);
        $bodies = implode("\n", array_map(static fn($place): string => implode("\n", array_map(static fn($message): string => $message->getBody(), $place->getConversation()->getMessages()->toArray())), $places));
        self::assertStringContainsString('150 antimatière', $bodies);
        self::assertSame(1, substr_count($bodies, 'Vous remportez le plan du vaisseau « Croiseur d’antimatière »'));
    }
}
