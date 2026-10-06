<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\ScheduledEvent;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\UserFactory;
use App\Service\Economy\BuildingCompletedHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Tableau de bord d'administration : indicateurs clés et alertes d'exploitation (§5.6.1).
 */
final class DashboardAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testShowsPlayerAndEventIndicators(): void
    {
        self::mockTime('2026-10-06 12:00:00');
        UserFactory::createMany(2, ['registeredAt' => new \DateTimeImmutable('2026-10-06 08:00:00')]);
        $this->scheduleBuildingCompletion('2026-10-06 13:00:00');
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#indicateurs-joueurs', '2 Inscrits');
        self::assertSelectorTextContains('#indicateurs-joueurs', '2 Nouveaux (24 h)');
        self::assertSelectorTextContains('#indicateurs-evenements', '1 Constructions en cours');
        // Alertes d'exploitation : section Exploitation, rôle Admin (§5.6.2)
        self::assertSelectorNotExists('#alertes-exploitation');
    }

    public function testAdminSeesLateEventAlert(): void
    {
        self::mockTime('2026-10-06 12:00:00');
        $this->scheduleBuildingCompletion('2026-10-06 11:00:00');
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('#alertes-exploitation .alert-warning', '1 événement(s) planifié(s) en retard');
    }

    public function testAdminSeesNoAlertOnHealthyGame(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');

        $this->client->request('GET', '/admin');

        self::assertSelectorTextContains('#alertes-exploitation', 'Aucune alerte.');
    }

    private function scheduleBuildingCompletion(string $dueAt): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ScheduledEvent(BuildingCompletedHandler::TYPE, new \DateTimeImmutable($dueAt), new \DateTimeImmutable('2026-10-06 10:00:00')));
        $entityManager->flush();
    }
}
