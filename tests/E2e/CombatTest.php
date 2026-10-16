<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Service\Combat\BattleResolutionHandler;

/**
 * Parcours « combat » (§4.7, §4.12) : un joueur attaque la planète d'un autre, qui voit l'alerte d'attaque entrante
 * puis reçoit le rapport dans sa messagerie ; l'assaillant lit le rapport détaillé.
 */
final class CombatTest extends E2eTestCase
{
    public function testAttackOnAPlanetEndsWithACombatReportForBothSides(): void
    {
        $attacker = EmpireFactory::createOne();
        $home = $attacker->getHomePlanet()->getSystem();
        $defender = EmpireFactory::createOne(['homePlanet' => PlanetFactory::new(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 800, $home->getPosition()->y),
        ])])]);
        $defender->getHomePlanet()->addShips($this->ship('rocket_launcher'), 2);
        $fleet = new Fleet($attacker, 'Escadre Orion', $attacker->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($this->ship('cruiser'), 5);
        $fleet->refuel($fleet->tankCapacity());
        $this->entityManager()->persist($fleet);
        $this->entityManager()->flush();
        $target = $defender->getHomePlanet();

        // L'assaillant rejoint une bataille sur la planète visée
        $this->logIn($attacker);
        $crawler = $this->browser->request('GET', \sprintf('/flotte/%d/envoyer', $fleet->getId()));
        $address = $target->getAddress();
        $this->browser->submit($crawler->selectButton('Envoyer la flotte')->form([
            'steps[0][galaxy]' => (string) $address->galaxy,
            'steps[0][system]' => (string) $address->system,
            'steps[0][position]' => (string) $address->position,
            'steps[0][action]' => 'battle',
            'steps[2][action]' => '',
        ]));
        $this->browser->waitFor('#flottes');
        self::assertSelectorTextContains('#flottes', \sprintf('Bataille programmée en %s', $target->getAddress()));

        // Le défenseur est prévenu, compte à rebours compris
        $this->browser->getCookieJar()->clear();
        $this->logIn($defender);
        self::assertSelectorTextContains('#sg-incoming-attacks', \sprintf('Vous êtes attaqué : %s vise %s', $attacker->getName(), $target));

        $this->resolveUntil(BattleResolutionHandler::TYPE);

        // Rapport automatique dans sa messagerie ; l'alerte a disparu
        $this->browser->request('GET', '/messagerie');
        $this->browser->waitFor('#conversations-inbox');
        self::assertSelectorTextContains('#conversations-inbox', \sprintf('Défaite : %s', $target->getAddress()));
        self::assertSelectorNotExists('#sg-incoming-attacks [id^="attaque-"]');

        // L'assaillant lit le rapport détaillé
        $this->browser->getCookieJar()->clear();
        $this->logIn($attacker);
        $crawler = $this->browser->request('GET', '/combats');
        // Les pastilles sont en capitales (CSS) : le navigateur rend « VICTOIRE »
        self::assertStringContainsStringIgnoringCase('Victoire', $crawler->filter('#rapports')->text());
        $this->browser->click($crawler->selectLink('Lire le rapport')->link());
        $crawler = $this->browser->waitFor('#rounds');
        self::assertStringContainsStringIgnoringCase('Victoire', $crawler->filter('main')->text());
        self::assertSelectorTextContains('main', 'Lanceur de missiles');
    }
}
