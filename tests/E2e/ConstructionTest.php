<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Factory\EmpireFactory;
use App\Service\Economy\BuildingCompletedHandler;

/**
 * Parcours « construction » (§4.3) : le joueur lance un bâtiment, suit le compte à rebours, puis le trouve achevé.
 */
final class ConstructionTest extends E2eTestCase
{
    public function testBuildsTheFirstLevelOfAMine(): void
    {
        $empire = EmpireFactory::createOne();
        $this->logIn($empire);

        $crawler = $this->browser->request('GET', '/batiments');
        $mine = $crawler->filter('.sg-entity')->first();
        self::assertStringContainsString('Mine de métal', $mine->text());
        $mine->selectButton('Construire niveau 1')->click();

        $this->browser->waitFor('.sg-queue [data-controller="countdown"]');
        self::assertSelectorTextContains('.sg-alert', 'Construction lancée : Mine de métal niveau 1.');
        // Compte à rebours animé par le contrôleur Stimulus « countdown »
        $remaining = '.sg-queue [data-controller="countdown"] [data-countdown-target="remaining"]';
        $before = $this->browser->getCrawler()->filter($remaining)->text();
        $this->browser->waitForElementToNotContain($remaining, $before, 5);

        $this->resolveUntil(BuildingCompletedHandler::TYPE);

        $crawler = $this->browser->request('GET', '/batiments');
        self::assertSelectorNotExists('.sg-queue [data-controller="countdown"]');
        self::assertStringContainsString('Niv. 1', $crawler->filter('.sg-entity')->first()->text());
    }
}
