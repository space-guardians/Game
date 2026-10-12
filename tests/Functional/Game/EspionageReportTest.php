<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\EspionageReport;
use App\Factory\EmpireFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Espionnage » (§4.9) : rapports de l'empire seul, sections révélées ou non.
 */
final class EspionageReportTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testSpyReadsItsReport(): void
    {
        [$spy, $report] = $this->report();
        $this->client->loginUser($spy->getUser());

        $this->client->request('GET', '/espionnage');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Espionnage');
        self::assertSelectorTextContains('#rapports-espionnage', 'Cible');
        $this->client->clickLink('Lire le rapport');

        self::assertSelectorTextContains('#cible', 'Cible');
        self::assertSelectorTextContains('#resources', 'Métal');
        self::assertSelectorTextContains('#fleets', 'Garde');
        self::assertSelectorTextContains('#fleets', 'Croiseur');
        self::assertSelectorTextContains('#defenses', 'Non révélé : niveau de détail insuffisant.');
        self::assertSelectorTextContains('#research', 'Non révélé');
        self::assertSelectorTextContains('#detection', 'Risque de détection : 2 %');
        self::assertSelectorTextContains('#detection', 'vos sondes ont été repérées');
    }

    public function testOthersCannotReadIt(): void
    {
        [, $report] = $this->report();
        $this->client->loginUser(EmpireFactory::createOne()->getUser());

        $this->client->request('GET', '/espionnage/' . $report->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{Empire, EspionageReport} */
    private function report(): array
    {
        $spy = EmpireFactory::createOne();
        $report = new EspionageReport($spy, '1:42:7', 'Cible', new \DateTimeImmutable('2026-10-11 12:00:00'), 0, [
            'resources' => ['metal' => 5000.0, 'crystal' => 3000.0, 'deuterium' => 1000.0],
            'fleets' => [['name' => 'Garde', 'empire' => 'Cible', 'ships' => ['Croiseur' => 4]]],
        ], 0.02, true);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($report);
        $entityManager->flush();

        return [$spy, $report];
    }
}
