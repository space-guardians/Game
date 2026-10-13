<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Fleet;
use App\Factory\EmpireFactory;
use App\Repository\ShipTypeRepository;
use App\Service\Alliance\Alliances;
use App\Service\Ranking\Rankings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Classement » (§4.11) : global et par catégorie, empires et alliances, rang du joueur.
 */
final class RankingTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEmpireAndAllianceRankings(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('battleship');
        \assert(null !== $type);
        $fleet = new Fleet($bob, 'Armada', $bob->getHomePlanet(), new \DateTimeImmutable('2026-10-13'));
        $fleet->addShips($type, 50);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();
        self::getContainer()->get(Alliances::class)->found($bob, 'Horde', 'HRD');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/classement');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Classement');
        self::assertSelectorTextContains('#mon-rang', 'pas encore classé');

        self::getContainer()->get(Rankings::class)->refresh();
        $this->client->request('GET', '/classement?categorie=militaire');
        self::assertSelectorTextContains('#ranking-title', 'Militaire — empires');
        self::assertSelectorTextContains('#classement tbody tr:first-child', 'Bob');
        self::assertSelectorTextContains('#classement tbody tr:first-child', '[HRD]');
        self::assertSelectorTextContains('#classement tr[aria-current="true"]', 'Ana');
        self::assertSelectorTextContains('#mon-rang', '2e');

        $this->client->clickLink('Alliances');
        self::assertSelectorTextContains('#ranking-title', 'Militaire — alliances');
        self::assertSelectorTextContains('#classement tbody tr:first-child', '[HRD] Horde');
    }
}
