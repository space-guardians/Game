<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Pve;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\Merchant;
use App\Entity\PlayerQuest;
use App\Entity\ScheduledEvent;
use App\Enum\Market\PlayerQuestStatus;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Repository\FleetMovementRepository;
use App\Repository\FleetRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\PlayerQuestRepository;
use App\Service\Pve\NpcCivilisations;
use App\Service\Pve\NpcConvoys;
use App\Service\Scheduling\ScheduledEventResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Convois des guildes marchandes (§4.15) : contrats d'escorte postés par le jeu, récompense versée par le jeu, un convoi
 * à la fois, à l'intervalle du profil.
 */
final class NpcConvoysTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-17 10:00:00');
    }

    public function testGuildPostsAnEscortContractForAConvoyToAMerchantStation(): void
    {
        $guild = $this->guild();
        // Pas de flotte stationnée à la fondation : la composition du profil est celle des convois
        self::assertSame([], $this->fleets()->findOwnedBy($guild));
        $merchant = $this->merchantNear($guild);
        $stock = $guild->getHomePlanet()->getResources();

        self::assertSame(1, $this->convoys()->launchDue());

        $quest = $this->quests()->findOneBy(['poster' => $guild]);
        self::assertInstanceOf(PlayerQuest::class, $quest);
        self::assertSame(PlayerQuestStatus::Open, $quest->getStatus());
        self::assertSame('comptoir ' . $merchant->getName(), $quest->getDestinationLabel());
        self::assertEquals(new \DateTimeImmutable('2026-10-17 12:00:00'), $quest->getDepartsAt());
        self::assertEqualsWithDelta(3000.0, $quest->getReward()->metal, 0.1);
        // Récompense versée par le jeu : le stock de la guilde n'en est pas entamé
        self::assertEqualsWithDelta($stock->metal, $guild->getHomePlanet()->getResources()->metal, 1.0);
        self::assertSame(12, $quest->getConvoy()?->shipCount());
        // Un convoi à la fois, et pas avant l'intervalle du profil
        self::assertSame(0, $this->convoys()->launchDue());

        // Voyage sans escorte : arrivé sans perte, la récompense revient à la guilde
        $this->clock->sleep(2 * 3600);
        $this->resolve($quest->getDepartureEvent());
        $convoy = $this->entityManager()->find(Fleet::class, $quest->getConvoy()->getId());
        \assert($convoy instanceof Fleet);
        $movement = self::getContainer()->get(FleetMovementRepository::class)->findActiveFor($convoy);
        \assert(null !== $movement);
        $this->clock->sleep($movement->getDurationSeconds());
        $this->resolve($movement->getEvent());
        self::assertSame(PlayerQuestStatus::Succeeded, $this->quests()->find($quest->getId())?->getStatus());

        // Intervalle écoulé : le convoi arrivé est retiré, un nouveau est publié
        $this->clock->sleep(6 * 3600);
        self::assertSame(1, $this->convoys()->launchDue());
        $fleets = $this->fleets()->findOwnedBy($this->reload($guild));
        self::assertCount(1, $fleets);
        self::assertTrue($fleets[0]->isAtHome());
    }

    public function testWithoutMerchantInTheGalaxyNoConvoyIsPosted(): void
    {
        $guild = $this->guild();

        self::assertSame(0, $this->convoys()->launchDue());
        self::assertSame('Aucun comptoir de marchand dans la galaxie de la guilde.', $this->convoys()->launch($guild));
        self::assertSame([], $this->fleets()->findOwnedBy($guild));
    }

    private function guild(): Empire
    {
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('merchant_guild');
        \assert(null !== $profile);

        return self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Guilde des Soleils', PlanetFactory::createOne());
    }

    private function merchantNear(Empire $empire): Merchant
    {
        $home = $empire->getHomePlanet()->getSystem();
        $system = StarSystemFactory::createOne(['galaxy' => $home->getGalaxy(), 'position' => new GlobalPosition($home->getPosition()->x + 1000, $home->getPosition()->y)]);
        $merchant = new Merchant($system, 'Comptoir du Levant', 3.0, -2.0);
        $this->entityManager()->persist($merchant);
        $this->entityManager()->flush();

        return $merchant;
    }

    private function resolve(?ScheduledEvent $event): void
    {
        self::assertNotNull($event);
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $event->getId());
        $resolved = $this->entityManager()->find(ScheduledEvent::class, $event->getId());
        self::assertSame(ScheduledEventStatus::Done, $resolved?->getStatus(), (string) $resolved?->getError());
    }

    private function reload(Empire $empire): Empire
    {
        $found = $this->entityManager()->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }

    private function convoys(): NpcConvoys
    {
        return self::getContainer()->get(NpcConvoys::class);
    }

    private function quests(): PlayerQuestRepository
    {
        return self::getContainer()->get(PlayerQuestRepository::class);
    }

    private function fleets(): FleetRepository
    {
        return self::getContainer()->get(FleetRepository::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
