<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ScheduledEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Sonde de supervision (docs/exploitation.md) : publique, sans session, 503 dès qu'une dépendance faillit.
 */
final class HealthTest extends WebTestCase
{
    use ClockSensitiveTrait;

    public function testReportsEveryDependencyWithoutLoggingIn(): void
    {
        $client = self::createClient();

        $client->request('GET', '/sante');

        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok', 'worker' => 'ok']], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseNotHasCookie('PHPSESSID');
    }

    public function testEventsWaitingTooLongMeanTheWorkerIsDown(): void
    {
        $client = self::createClient();
        $now = self::mockTime('2026-10-16 12:00:00')->now();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ScheduledEvent('building.completed', $now->modify('-10 minutes'), $now->modify('-1 hour')));
        $entityManager->flush();

        $client->request('GET', '/sante');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['status' => 'error', 'checks' => ['database' => 'ok', 'redis' => 'ok', 'worker' => 'late']], json_decode((string) $client->getResponse()->getContent(), true));
    }
}
