<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Battle;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Bandeau « vous êtes attaqué » (§4.12) : sur chaque écran connecté du défenseur, avec compte à rebours jusqu'à
 * l'arrivée ; jamais chez l'assaillant.
 */
final class IncomingAttackTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testTheDefenderSeesACountdownOnEveryScreen(): void
    {
        $client = self::createClient();
        $clock = self::mockTime('2026-10-15 10:00:00');
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $home = $ana->getHomePlanet()->getSystem();
        $bob = EmpireFactory::createOne(['name' => 'Bob', 'homePlanet' => PlanetFactory::new(['system' => StarSystemFactory::new(['galaxy' => $home->getGalaxy(), 'position' => new GlobalPosition($home->getPosition()->x + 900, $home->getPosition()->y)])])]);
        $fleet = new Fleet($ana, 'Escadre', $ana->getHomePlanet(), $clock->now());
        $fighter = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($fighter instanceof ShipType);
        $fleet->addShips($fighter, 3);
        $battle = new Battle(SpaceLocation::of(SpacePosition::planet($bob->getHomePlanet())), 'Bob', $clock->now(), $clock->now()->modify('+12 minutes'));
        $battle->join($fleet, CombatSide::Attacker, $clock->now(), 100);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->persist($battle);
        $entityManager->flush();

        $client->loginUser($bob->getUser());
        foreach (['/flotte', '/batiments'] as $page) {
            $client->request('GET', $page);
            self::assertSelectorTextContains('#sg-incoming-attacks .sg-alert--attack', 'Vous êtes attaqué : Ana vise');
        }
        self::assertSelectorTextContains('#sg-incoming-attacks [data-countdown-target="remaining"]', '12 min');
        self::assertSelectorExists(\sprintf('#sg-incoming-attacks [data-controller="countdown"][data-countdown-end-value="%d"]', $battle->getResolvesAt()->getTimestamp() * 1000));
        self::assertSelectorTextContains('#sg-incoming-attacks', '(à 10:12:00)');

        $client->loginUser($ana->getUser());
        $client->request('GET', '/flotte');
        self::assertSelectorNotExists('#sg-incoming-attacks .sg-alert');
    }
}
