<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Battle;
use App\Entity\CombatReport;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Combat\CombatSide;
use App\Factory\EmpireFactory;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Combats » (§4.7) : rapports des batailles où l'empire était engagé.
 */
final class CombatReportTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testEachSideReadsTheReportWithItsOutcome(): void
    {
        [$attacker, $defender, $report] = $this->report();

        $this->client->loginUser($attacker->getUser());
        $this->client->request('GET', '/combats');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Combats');
        self::assertSelectorTextContains('#rapports', 'Victoire');
        $this->client->clickLink('Lire le rapport');

        self::assertSelectorTextContains('#issue', 'Victoire');
        self::assertSelectorTextContains('#issue', 'vous combattiez côté attaquant');
        self::assertSelectorTextContains('#camp-defender', 'attaqué de face');
        self::assertSelectorTextContains('#groupes-attacker', 'Croiseur');
        self::assertSelectorTextContains('#groupes-attacker', 'Avant · Centre');
        self::assertSelectorTextContains('#groupes-defender', 'Chasseur léger');
        self::assertSelectorCount(2, '#rounds tbody tr');
        self::assertSelectorTextContains('#debris', 'Champ de débris');

        $this->client->loginUser($defender->getUser());
        $this->client->request('GET', '/combats/' . $report->getId());
        self::assertSelectorTextContains('#issue', 'Défaite');
    }

    public function testOutsidersCannotReadTheReport(): void
    {
        [, , $report] = $this->report();
        $this->client->loginUser(EmpireFactory::createOne()->getUser());

        $this->client->request('GET', '/combats/' . $report->getId());

        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/combats');
        self::assertSelectorTextContains('main', 'Aucun combat pour l’instant.');
    }

    /** @return array{Empire, Empire, CombatReport} */
    private function report(): array
    {
        $attacker = EmpireFactory::createOne();
        $defender = EmpireFactory::createOne();
        $planet = $defender->getHomePlanet();
        $at = new \DateTimeImmutable('2026-10-11 12:00:00');
        $battle = new Battle(SpaceLocation::of(SpacePosition::planet($planet)), (string) $planet->getAddress(), $at, $at);
        $battle->join($this->fleet($attacker, 'cruiser'), CombatSide::Attacker, $at);
        $battle->join($this->fleet($defender, 'light_fighter'), CombatSide::Defender, $at);
        $battle->resolve(CombatSide::Attacker, 2, [], $at);
        $report = new CombatReport($battle, $at, (string) $planet->getAddress(), CombatSide::Attacker, ['attacker' => 'front', 'defender' => 'front'], [
            ['side' => 'attacker', 'fleet' => 'Raid', 'empire' => $attacker->getName(), 'type' => 'Croiseur', 'class' => 'cruiser', 'row' => 'front', 'column' => 'center', 'count' => 10, 'lost' => 0, 'dealt' => 24000.0, 'received' => 300.0],
            ['side' => 'defender', 'fleet' => 'Garde', 'empire' => $defender->getName(), 'type' => 'Chasseur léger', 'class' => 'interceptor', 'row' => 'front', 'column' => 'center', 'count' => 6, 'lost' => 6, 'dealt' => 300.0, 'received' => 24000.0],
        ], [
            ['number' => 1, 'shots' => ['attacker' => 10, 'defender' => 6], 'hits' => ['attacker' => 8, 'defender' => 5], 'absorbed' => ['attacker' => 80.0, 'defender' => 250.0], 'damage' => ['attacker' => 16000.0, 'defender' => 0.0], 'losses' => 4],
            ['number' => 2, 'shots' => ['attacker' => 10, 'defender' => 2], 'hits' => ['attacker' => 8, 'defender' => 2], 'absorbed' => ['attacker' => 40.0, 'defender' => 100.0], 'damage' => ['attacker' => 8000.0, 'defender' => 0.0], 'losses' => 2],
        ], ['metal' => 5400.0, 'crystal' => 1800.0]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($battle);
        $entityManager->persist($report);
        $entityManager->flush();

        return [$attacker, $defender, $report];
    }

    private function fleet(Empire $empire, string $code): Fleet
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable());
        $fleet->addShips($type, 2);
        self::getContainer()->get(EntityManagerInterface::class)->persist($fleet);

        return $fleet;
    }
}
