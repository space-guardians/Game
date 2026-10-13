<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Messaging;

use App\Entity\Conversation;
use App\Entity\Empire;
use App\Enum\Messaging\SystemAudience;
use App\Exception\Messaging\InvalidMessage;
use App\Factory\EmpireFactory;
use App\Message\DeliverSystemMessage;
use App\Repository\AllianceMemberRepository;
use App\Repository\ConversationRepository;
use App\Service\Alliance\Alliances;
use App\Service\Messaging\Messaging;
use App\Service\Messaging\SystemMessenger;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * Messages système (§4.12.2, §5.7) : conversation du jeu sans réponse, destinataires désignés servis aussitôt,
 * alliance et tous les joueurs servis en asynchrone par lots.
 */
final class SystemMessengerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
    }

    public function testAMessageToAnEmpireCannotBeAnswered(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $conversation = $this->messenger()->toEmpires([$ana, $bob], 'Maintenance', 'Le serveur redémarre à minuit.');

        self::assertTrue($conversation->isSystem());
        self::assertSame('Maintenance', $conversation->titleFor($ana));
        $message = $conversation->getMessages()->first();
        self::assertNotFalse($message);
        self::assertNull($message->getAuthor());
        foreach ([$ana, $bob] as $empire) {
            $place = $conversation->participantOf($empire);
            self::assertNotNull($place);
            self::assertTrue($place->sees($message));
            self::assertTrue($place->hasUnread());
            self::assertSame(1, self::getContainer()->get(Messaging::class)->unreadCount($empire));
        }
        // Annoncé en direct à chacun
        $topics = array_map(static fn($update): string => $update->getTopics()[0], self::getContainer()->get(PublishedUpdates::class)->all());
        self::assertContains('/empire/' . $ana->getId(), $topics);
        self::assertContains('/empire/' . $bob->getId(), $topics);

        $this->expectExceptionObject(new InvalidMessage('Un message du jeu n’appelle pas de réponse.'));
        self::getContainer()->get(Messaging::class)->reply($conversation, $ana, 'Merci !');
    }

    public function testASystemMessageIsNeverTakenForAPrivateConversation(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $announcement = $this->messenger()->toEmpires([$ana, $bob], null, 'Bienvenue !');

        $private = self::getContainer()->get(Messaging::class)->start($ana, ['Bob'], null, 'Salut Bob');

        self::assertNotSame($announcement, $private);
        self::assertFalse($private->isSystem());
        self::assertSame('Message du jeu', $announcement->titleFor($ana));
    }

    public function testAllianceAndEveryoneAreServedAsynchronouslyOnce(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob', 'Cid');
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($ana, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($ana, 'Bob'), $bob);
        $alliance = self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($ana)?->getAlliance();
        \assert(null !== $alliance);

        $toAlliance = $this->messenger()->toAlliance($alliance, 'Ligue', 'Réunion ce soir.');
        // Rien de livré dans la requête : un envoi en file asynchrone
        self::assertCount(0, $toAlliance->getParticipants());
        $sent = $this->transport()->getSent();
        self::assertCount(1, $sent);
        $delivery = $sent[0]->getMessage();
        self::assertInstanceOf(DeliverSystemMessage::class, $delivery);
        self::assertSame(SystemAudience::Alliance, $delivery->audience);

        self::assertSame(2, $this->messenger()->deliverAsync($delivery));
        $this->assertRecipients($toAlliance, ['Ana', 'Bob']);
        // Rejouée, la livraison ne sert personne deux fois
        self::assertSame(0, $this->messenger()->deliverAsync($delivery));

        $toAll = $this->messenger()->toAll('Annonce', 'Nouvelle saison !');
        $all = $this->transport()->getSent()[1]->getMessage();
        \assert($all instanceof DeliverSystemMessage);
        $everyone = (int) self::getContainer()->get(EntityManagerInterface::class)->createQuery('SELECT COUNT(e.id) FROM ' . Empire::class . ' e')->getSingleScalarResult();
        self::assertSame($everyone, $this->messenger()->deliverAsync($all));
        $this->assertRecipients($toAll, ['Ana', 'Bob', 'Cid'], exact: false);
    }

    public function testEmptyMessagesAndRecipientsAreRefused(): void
    {
        [$ana] = $this->empires('Ana');

        foreach ([
            [fn() => $this->messenger()->toEmpire($ana, null, '   '), 'Le message est vide.'],
            [fn() => $this->messenger()->toEmpires([], null, 'Bonjour'), 'Indiquez au moins un destinataire.'],
        ] as [$attempt, $error]) {
            try {
                $attempt();
                self::fail('Envoi accepté à tort : ' . $error);
            } catch (InvalidMessage $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
    }

    /** @param list<string> $names */
    private function assertRecipients(Conversation $conversation, array $names, bool $exact = true): void
    {
        $conversation = self::getContainer()->get(ConversationRepository::class)->find($conversation->getId());
        \assert(null !== $conversation);
        $recipients = array_map(static fn($place): string => $place->getEmpire()->getName(), $conversation->getParticipants()->toArray());
        if ($exact) {
            self::assertEqualsCanonicalizing($names, $recipients);
        } else {
            self::assertEmpty(array_diff($names, $recipients));
        }
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function messenger(): SystemMessenger
    {
        return self::getContainer()->get(SystemMessenger::class);
    }
}
