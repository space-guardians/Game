<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FleetAction;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\IncomingAttacks;
use App\Service\Fleet\FleetDispatch;
use App\Service\Notification\Notifications;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Alerte « attaque entrante » (§4.12) : détectée dès la programmation d'une bataille sur une planète de l'empire par un
 * empire non allié, poussée en direct avec son heure d'arrivée.
 */
final class IncomingAttacksTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 10:00:00');
    }

    public function testAnAttackIsDetectedAndPushedToTheDefenderUntilItIsResolved(): void
    {
        [$ana, $bob] = $this->neighbours();

        $movement = self::getContainer()->get(FleetDispatch::class)->dispatch($this->fleet($ana), [new MissionStep(SpacePosition::planet($bob->getHomePlanet()), FleetAction::JoinBattle, 'Bob')], 100, new Resources());

        $attacks = $this->attacks()->of($bob);
        self::assertCount(1, $attacks);
        self::assertSame($ana, $attacks[0]->attacker);
        self::assertSame($bob->getHomePlanet(), $attacks[0]->planet);
        self::assertEquals($movement->getArrivesAt(), $attacks[0]->arrivesAt);
        self::assertSame([], $this->attacks()->of($ana));

        // Poussée avec la notification : bandeau remis à jour avec son compte à rebours
        self::getContainer()->get(Notifications::class)->publish();
        $streams = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), 'target="sg-incoming-attacks"')));
        self::assertCount(1, $streams);
        self::assertSame(['/empire/' . $bob->getId()], $streams[0]->getTopics());
        self::assertStringContainsString('Vous êtes attaqué : Ana vise', $streams[0]->getData());
        self::assertStringContainsString(\sprintf('data-countdown-end-value="%d"', $movement->getArrivesAt()->getTimestamp() * 1000), $streams[0]->getData());

        // Résolue : plus d'alerte
        $battle = self::getContainer()->get(EntityManagerInterface::class)->find(Battle::class, $attacks[0]->battleId);
        \assert($battle instanceof Battle);
        $battle->resolve(CombatSide::Defender, 1, [], $movement->getArrivesAt());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame([], $this->attacks()->of($bob));
    }

    public function testOnlyABattleLaunchedByAnotherEmpireIsAnAttack(): void
    {
        [$ana, $bob] = $this->neighbours();
        $battle = new Battle(SpaceLocation::of(SpacePosition::planet($bob->getHomePlanet())), 'Bob', $this->clock->now(), $this->clock->now()->modify('+10 minutes'));
        $fleet = $this->fleet($ana);
        $battle->join($fleet, CombatSide::Attacker, $this->clock->now(), 100);
        self::getContainer()->get(EntityManagerInterface::class)->persist($battle);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertCount(1, $this->attacks()->of($bob));

        // Même empire : une bataille sur sa propre planète n'est pas une attaque
        $own = new Battle(SpaceLocation::of(SpacePosition::planet($ana->getHomePlanet())), 'Ana', $this->clock->now(), $this->clock->now()->modify('+10 minutes'));
        $own->join($this->fleet($ana), CombatSide::Attacker, $this->clock->now(), 100);
        self::getContainer()->get(EntityManagerInterface::class)->persist($own);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::assertSame([], $this->attacks()->of($ana));
    }

    /** @return array{Empire, Empire} deux empires de la même galaxie */
    private function neighbours(): array
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $home = $ana->getHomePlanet()->getSystem();
        $bob = EmpireFactory::createOne(['name' => 'Bob', 'homePlanet' => PlanetFactory::new(['system' => StarSystemFactory::new(['galaxy' => $home->getGalaxy(), 'position' => new GlobalPosition($home->getPosition()->x + 900, $home->getPosition()->y)])])]);

        return [$ana, $bob];
    }

    private function fleet(Empire $empire): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), $this->clock->now());
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet->addShips($type, 3);
        $fleet->refuel($fleet->tankCapacity());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function attacks(): IncomingAttacks
    {
        return self::getContainer()->get(IncomingAttacks::class);
    }
}
