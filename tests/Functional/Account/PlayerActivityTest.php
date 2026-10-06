<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\EventListener\PlayerActivityRecorder;
use App\Factory\EmpireFactory;
use App\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Dernière activité des joueurs, pour les joueurs actifs du tableau de bord d'administration (§5.6.1).
 */
final class PlayerActivityTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testRecordsActivityAtMostEveryInterval(): void
    {
        $clock = self::mockTime('2026-10-06 12:00:00');
        $user = EmpireFactory::createOne()->getUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        self::assertSame('2026-10-06 12:00:00', $this->lastActiveAt($user));

        $clock->sleep(PlayerActivityRecorder::INTERVAL - 1);
        $this->client->request('GET', '/');
        self::assertSame('2026-10-06 12:00:00', $this->lastActiveAt($user));

        $clock->sleep(1);
        $this->client->request('GET', '/');
        self::assertSame('2026-10-06 12:05:00', $this->lastActiveAt($user));
    }

    public function testAnonymousVisitIsNotRecorded(): void
    {
        $user = UserFactory::createOne();

        $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertNull($this->lastActiveAt($user));
    }

    private function lastActiveAt(User $user): ?string
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne('SELECT last_active_at FROM "user" WHERE id = ?', [$user->getId()]);

        return false === $value ? null : $value;
    }
}
