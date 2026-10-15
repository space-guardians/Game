<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\Empire;
use App\Entity\OrbitalPosition;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Repository\EmpireRepository;
use App\Repository\FleetRepository;
use App\Repository\NpcBehaviorProfileRepository;
use App\Service\Combat\IncomingAttacks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Back-office des civilisations mineures (§4.14, §5.6.1) : fondation, renfort de flotte, ordre d'attaque manuel, dès
 * le rôle GameDesigner.
 */
final class NpcCivilisationAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-15 10:00:00');
    }

    public function testModeratorCannotManageCivilisations(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/civilisations');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerFoundsReinforcesAndOrdersAnAttack(): void
    {
        $player = EmpireFactory::createOne(['name' => 'Ana']);
        // Orbite distincte de celle de la planète mère : une seule planète par orbite d'un système
        $orbit = $player->getHomePlanet()->getPosition()->orbit % OrbitalPosition::MAX_ORBIT + 1;
        $free = PlanetFactory::createOne(['system' => $player->getHomePlanet()->getSystem(), 'position' => new OrbitalPosition($orbit, $orbit * 10.0, 0.0)]);
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');

        $crawler = $this->client->request('GET', '/admin/civilisations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#civilisations', 'Aucune civilisation pour l’instant.');
        $this->client->submit($crawler->filter('#fonder')->form(['profile' => (string) self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider')?->getId(), 'name' => 'Pillards', 'coordinates' => (string) $free->getAddress()->galaxy . ':' . $free->getAddress()->system . ':' . $free->getAddress()->position]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Civilisation « Pillards » fondée sur');
        $npc = self::getContainer()->get(EmpireRepository::class)->findOneBy(['name' => 'Pillards']);
        self::assertInstanceOf(Empire::class, $npc);
        self::assertTrue($npc->isNpc());

        $row = $crawler->filter(\sprintf('#civilisation-%d', $npc->getId()));
        $this->client->submit($row->selectButton('Renforcer')->form(['quantity' => '5']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'renforcée');

        $address = $player->getHomePlanet()->getAddress();
        $row = $crawler->filter(\sprintf('#civilisation-%d', $npc->getId()));
        $this->client->submit($row->selectButton('Attaquer')->form(['coordinates' => \sprintf('%d:%d:%d', $address->galaxy, $address->system, $address->position)]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Ordre d’attaque donné à « Pillards ».');
        self::assertCount(1, self::getContainer()->get(IncomingAttacks::class)->of(self::getContainer()->get(EmpireRepository::class)->find($player->getId()) ?? $player));
        self::assertNotEmpty(self::getContainer()->get(FleetRepository::class)->findBy(['empire' => $npc->getId()]));
        // Chaque action est notée au journal des actions
        $actions = array_map(static fn(AdminAuditLog $entry): AuditAction => $entry->getAction(), self::getContainer()->get(EntityManagerInterface::class)->getRepository(AdminAuditLog::class)->findBy([], ['id' => 'ASC']));
        self::assertSame([AuditAction::NpcFound, AuditAction::NpcOrder, AuditAction::NpcOrder], $actions);
    }
}
