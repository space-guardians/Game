<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\ScheduledEvent;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Factory\AdminUserFactory;
use App\Message\ResolveScheduledEvent;
use App\Repository\ScheduledEventRepository;
use App\Service\Admin\MessengerSupervision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * Supervision de l'exploitation (§5.6.1) : files Messenger et événements planifiés, relance et suppression
 * inscrites au journal des actions (§5.6.2), rôle Admin.
 */
final class OperationsAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testGameDesignerCannotSuperviseOperations(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');
        $event = $this->failedEvent();

        $this->client->request('GET', '/admin/files-messages');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/evenements-planifies');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', \sprintf('/admin/evenements-planifies/%d/relancer', $event->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('nav', 'Files de messages');
    }

    public function testRetriesFailedScheduledEvent(): void
    {
        self::mockTime('2026-10-06 12:00:00');
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');
        $event = $this->failedEvent();

        $this->client->request('GET', '/admin/evenements-planifies', ['filters' => ['status' => ['comparison' => '=', 'value' => 'failed']]]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'type.inconnu');
        $this->client->request('GET', \sprintf('/admin/evenements-planifies/%d', $event->getId()));
        self::assertSelectorTextContains('body', 'Aucun gestionnaire');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/relancer"]')->form());

        self::assertResponseRedirects();
        // Avant la requête suivante : le noyau redémarré viderait le transport en mémoire
        self::assertEquals([new ResolveScheduledEvent((int) $event->getId())], array_map(static fn($envelope): object => $envelope->getMessage(), $this->async()->getSent()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'relancé');
        self::assertSame(ScheduledEventStatus::Pending, self::getContainer()->get(ScheduledEventRepository::class)->currentStatus((int) $event->getId()));
        self::assertSame(AuditAction::Retry, $this->lastJournalEntry()->getAction());
    }

    public function testResolvedEventCannotBeRetried(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');
        $event = new ScheduledEvent('type.inconnu', new \DateTimeImmutable('2026-10-06 11:00:00'), new \DateTimeImmutable('2026-10-06 10:00:00'));
        $event->markDone(new \DateTimeImmutable('2026-10-06 11:00:00'));
        $this->persist($event);

        $this->client->request('GET', \sprintf('/admin/evenements-planifies/%d', $event->getId()));
        self::assertSelectorNotExists('form[action$="/relancer"]');
        $this->client->request('POST', \sprintf('/admin/evenements-planifies/%d/relancer', $event->getId()));
        self::assertSame([], $this->async()->getSent());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'rien à relancer');
    }

    public function testRetriesAndRemovesFailedMessages(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');
        $this->failMessage(new ResolveScheduledEvent(42));
        $this->failMessage(new ResolveScheduledEvent(43));

        $this->client->request('GET', '/admin/files-messages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#failed-count', '2');
        self::assertSelectorTextContains('#failed-messages', 'ResolveScheduledEvent');
        self::assertSelectorTextContains('#failed-messages', 'Base indisponible');

        $this->client->submit($this->client->getCrawler()->filter('#failed-messages form[action$="/relancer"]')->first()->form());
        self::assertResponseRedirects('/admin/files-messages');
        self::assertCount(1, $this->async()->getSent());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'renvoyé dans la file « async »');
        self::assertSame(AuditAction::Retry, $this->lastJournalEntry()->getAction());

        $this->client->submit($this->client->getCrawler()->filter('#failed-messages form[action$="/supprimer"]')->first()->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'supprimé');
        self::assertSelectorTextContains('#failed-messages', 'Aucun message en échec.');
        self::assertSame(AuditAction::Discard, $this->lastJournalEntry()->getAction());
        self::assertSame('QueuedMessage', $this->lastJournalEntry()->getSubjectType());
    }

    public function testMessageActionRequiresValidToken(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Admin]), 'admin');
        $this->failMessage(new ResolveScheduledEvent(42));
        $id = self::getContainer()->get(MessengerSupervision::class)->list(MessengerSupervision::FAILED_TRANSPORT)[0]->id;

        $this->client->request('POST', \sprintf('/admin/files-messages/%s/supprimer', $id), ['_token' => 'invalide']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'La page a expiré');
        self::assertSame(1, self::getContainer()->get(MessengerSupervision::class)->count(MessengerSupervision::FAILED_TRANSPORT));
    }

    private function failedEvent(): ScheduledEvent
    {
        $event = new ScheduledEvent('type.inconnu', new \DateTimeImmutable('2026-10-06 11:00:00'), new \DateTimeImmutable('2026-10-06 10:00:00'));
        $event->markFailed('Aucun gestionnaire pour les événements « type.inconnu ».', new \DateTimeImmutable('2026-10-06 11:00:01'));
        $this->persist($event);

        return $event;
    }

    private function persist(object $entity): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entity);
        $entityManager->flush();
    }

    private function failMessage(object $message): void
    {
        self::getContainer()->get('messenger.transport.failed')->send(new Envelope($message, [
            new SentToFailureTransportStamp('async'),
            ErrorDetailsStamp::create(new \RuntimeException('Base indisponible')),
        ]));
    }

    private function lastJournalEntry(): AdminAuditLog
    {
        $entry = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AdminAuditLog::class)->findOneBy([], ['id' => 'DESC']);
        \assert($entry instanceof AdminAuditLog);

        return $entry;
    }

    private function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
