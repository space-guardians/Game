<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Battle;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Entity\SpaceLocation;
use App\Enum\Admin\AdminRole;
use App\Enum\Combat\CombatSide;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Model\Fleet\SpacePosition;
use App\Repository\NpcBehaviorProfileRepository;
use App\Repository\ShipTypeRepository;
use App\Service\Pve\BossFleets;
use App\Service\Pve\NpcCivilisations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Suivi des boss dans le panneau (§4.14, §5.6.1) : flotte courante, statut, raids subis.
 */
final class NpcMonitorAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-15 17:00:00');
    }

    public function testABossFileShowsItsFleetStatusAndRaidsSuffered(): void
    {
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $profile);
        $boss = self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Gardiens', PlanetFactory::createOne());
        self::getContainer()->get(BossFleets::class)->recalculate($boss);
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        $raid = new Fleet($player, 'Téméraires', $player->getHomePlanet(), new \DateTimeImmutable('2026-10-15 15:00'));
        $cruiser = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('cruiser');
        \assert($cruiser instanceof ShipType);
        $raid->addShips($cruiser, 2);
        $battle = new Battle(SpaceLocation::of(SpacePosition::planet($boss->getHomePlanet())), 'Gardiens', new \DateTimeImmutable('2026-10-15 15:00'), new \DateTimeImmutable('2026-10-15 16:00'));
        $battle->join($raid, CombatSide::Attacker, new \DateTimeImmutable('2026-10-15 15:00'), 100);
        $battle->resolve(CombatSide::Defender, 3, [], new \DateTimeImmutable('2026-10-15 16:00'));
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($raid);
        $entityManager->persist($battle);
        $entityManager->flush();
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');

        $crawler = $this->client->request('GET', '/admin/civilisations');
        $this->client->click($crawler->selectLink('Gardiens')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gardiens');
        self::assertSelectorTextContains('#statut', 'Flotte recalculée : 15/10/2026 17:00 ; prochain recalcul après le 16/10/2026 17:00');
        self::assertSelectorTextContains('#statut', 'Aucune attaque en cours.');
        self::assertSelectorTextContains('#flottes', 'Flotte de Gardiens');
        self::assertSelectorTextContains('#flottes', '× light_fighter');
        self::assertSelectorTextContains('#raids-subis', 'Ana');
        self::assertSelectorTextContains('#raids-subis', 'Repoussé');
        self::assertSelectorTextContains('#raids-subis', '15/10/2026 16:00');
    }

    public function testModeratorCannotSeeAFile(): void
    {
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $profile);
        $boss = self::getContainer()->get(NpcCivilisations::class)->found($profile, 'Gardiens', PlanetFactory::createOne());
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', \sprintf('/admin/civilisations/%d', $boss->getId()));

        self::assertResponseStatusCodeSame(403);
    }
}
