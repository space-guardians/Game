<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Empire;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Repository\NpcBehaviorProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Profils des civilisations NPC dans le panneau (§4.14, §5.6.1), dès le rôle GameDesigner ; les civilisations ne se
 * mêlent pas à la liste des joueurs.
 */
final class NpcProfileAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotTuneProfiles(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/profils-npc');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerTunesAProfileButABossNeverRaids(): void
    {
        $boss = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('boss_guardian');
        \assert(null !== $boss);
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');

        $this->client->request('GET', '/admin/profils-npc');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Pillards des confins');
        self::assertSelectorTextContains('table', 'Gardiens anciens');

        $this->client->request('GET', \sprintf('/admin/profils-npc/%d/edit', $boss->getId()));
        $this->client->submitForm('Sauvegarder les modifications', ['NpcBehaviorProfile[raidsEnabled]' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une civilisation « boss » est strictement défensive : pas de raids.');

        $this->client->submitForm('Sauvegarder les modifications', ['NpcBehaviorProfile[raidsEnabled]' => false, 'NpcBehaviorProfile[fleetStrengthPercent]' => '200']);
        self::assertResponseRedirects();
        self::assertSame(200, self::getContainer()->get(NpcBehaviorProfileRepository::class)->find($boss->getId())?->getFleetStrengthPercent());
    }

    public function testNpcEmpiresAreNotListedAmongPlayers(): void
    {
        EmpireFactory::createOne(['name' => 'Ordre d’Orion']);
        $profile = self::getContainer()->get(NpcBehaviorProfileRepository::class)->findOneByCode('minor_raider');
        \assert(null !== $profile);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(Empire::npc('Pillards', PlanetFactory::createOne(), $profile, new \DateTimeImmutable()));
        $entityManager->flush();
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/joueurs');

        self::assertSelectorTextContains('table', 'Ordre d’Orion');
        self::assertSelectorTextNotContains('table', 'Pillards');
    }
}
