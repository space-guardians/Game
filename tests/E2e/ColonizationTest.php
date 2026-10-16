<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Service\Fleet\FleetArrivalHandler;

/**
 * Parcours « colonisation » (§4.1, §4.6) : le joueur envoie un vaisseau de colonisation sur une planète libre, qui
 * rejoint ses planètes à l'arrivée.
 */
final class ColonizationTest extends E2eTestCase
{
    public function testColonizesAFreePlanet(): void
    {
        $empire = EmpireFactory::createOne();
        $empire->setResearchLevel($this->technology('astrophysics'), 1);
        $home = $empire->getHomePlanet();
        $target = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getSystem()->getGalaxy(),
            'position' => new GlobalPosition($home->getSystem()->getPosition()->x, $home->getSystem()->getPosition()->y + 1200),
        ])]);
        $fleet = new Fleet($empire, 'Pionniers', $home, new \DateTimeImmutable());
        $fleet->addShips($this->ship('colony_ship'), 1);
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();
        $this->logIn($empire);

        $crawler = $this->browser->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));
        self::assertSelectorTextContains('#emplacements-colonies', 'Colonies : 0 / 1');
        $address = $target->getAddress();
        $this->browser->submit($crawler->selectButton('Envoyer la flotte')->form([
            'steps[0][galaxy]' => (string) $address->galaxy,
            'steps[0][system]' => (string) $address->system,
            'steps[0][position]' => (string) $address->position,
            'steps[0][action]' => 'colonize',
            'steps[2][action]' => '',
        ]));

        $this->browser->waitFor('#flottes');
        self::assertSelectorTextContains('.sg-alert', 'Flotte « Pionniers » en route');
        self::assertSelectorTextContains('#flottes .sg-fleet__orders', 'Coloniser');

        $this->resolveUntil(FleetArrivalHandler::TYPE);

        $this->browser->request('GET', '/');
        self::assertSelectorTextContains('.sg-sidenav__planets', 'Colonie');
        self::assertSelectorTextContains('.sg-sidenav__planets', (string) $target);
    }
}
