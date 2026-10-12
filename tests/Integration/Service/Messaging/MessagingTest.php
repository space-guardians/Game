<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Messaging;

use App\Entity\Conversation;
use App\Entity\Empire;
use App\Exception\Messaging\InvalidMessage;
use App\Factory\EmpireFactory;
use App\Repository\ConversationRepository;
use App\Service\Messaging\Messaging;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Messagerie entre joueurs (§4.12.2) : conversations à deux et de groupe, état propre à chaque participant.
 */
final class MessagingTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-12 10:00:00');
    }

    public function testDirectConversationIsReusedAndReadState(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $first = $this->messaging()->start($ana, ['bob'], 'Salut', 'Bonjour Bob');
        $this->clock->sleep(60);
        $second = $this->messaging()->start($ana, ['Bob'], null, 'Tu es là ?');

        self::assertSame($first, $second);
        self::assertFalse($first->isGroup());
        self::assertCount(2, $first->getMessages());
        self::assertSame('Salut', $first->titleFor($bob));
        // L'auteur a lu, le destinataire non
        self::assertSame(0, $this->messaging()->unreadCount($ana));
        self::assertSame(1, $this->messaging()->unreadCount($bob));

        $this->messaging()->markRead($this->place($first, $bob));
        self::assertSame(0, $this->messaging()->unreadCount($bob));
    }

    public function testEachParticipantIsNotifiedInRealTime(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $conversation = $this->messaging()->start($ana, ['Bob'], null, 'Bonjour Bob');

        $updates = [];
        foreach (self::getContainer()->get(PublishedUpdates::class)->all() as $update) {
            $updates[$update->getTopics()[0]] = $update->getData();
        }
        $toBob = $updates['/empire/' . $bob->getId()] ?? '';
        self::assertStringContainsString('target="conversation-' . $conversation->getId() . '-messages"', $toBob);
        self::assertStringContainsString('Bonjour Bob', $toBob);
        self::assertStringContainsString('<strong>Ana</strong>', $toBob);
        self::assertMatchesRegularExpression('/target="sg-unread-messages">\s*<template>1<\/template>/', $toBob);
        self::assertStringContainsString('<strong>Vous</strong>', $updates['/empire/' . $ana->getId()] ?? '');
    }

    public function testGroupConversationAddingAndLeaving(): void
    {
        [$ana, $bob, $cyd, $dan] = $this->empires('Ana', 'Bob', 'Cyd', 'Dan');
        $group = $this->messaging()->start($ana, ['Bob', 'Cyd'], 'Expédition', 'On part demain');
        self::assertTrue($group->isGroup());

        $this->clock->sleep(60);
        $this->messaging()->addParticipants($group, $bob, ['Dan']);
        $this->clock->sleep(60);
        $this->messaging()->reply($group, $cyd, 'Je viens');

        // Dan ne voit que ce qui a suivi son arrivée
        $danPlace = $this->place($group, $dan);
        self::assertSame(['Je viens'], $this->visibleBodies($group, $dan));
        self::assertTrue($danPlace->hasUnread());

        $this->messaging()->leave($group, $cyd);
        $this->clock->sleep(60);
        $this->messaging()->reply($group, $ana, 'Sans Cyd, alors');
        self::assertSame(['On part demain', 'Je viens'], $this->visibleBodies($group, $cyd));
        self::assertSame(0, $this->messaging()->unreadCount($cyd));

        $this->expectExceptionObject(new InvalidMessage('Vous ne participez plus à cette conversation.'));
        $this->messaging()->reply($group, $cyd, 'Attendez-moi');
    }

    public function testArchivingAndDeletingOnOnesOwnSide(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $conversation = $this->messaging()->start($ana, ['Bob'], null, 'Premier');
        $bobs = $this->place($conversation, $bob);

        $this->messaging()->archive($bobs, true);
        self::assertSame([], $this->repository()->findPlacesOf($bob));
        self::assertCount(1, $this->repository()->findPlacesOf($bob, archived: true));

        // Un nouveau message la ramène dans la boîte
        $this->clock->sleep(60);
        $this->messaging()->reply($conversation, $ana, 'Deuxième');
        self::assertCount(1, $this->repository()->findPlacesOf($bob));

        // Supprimée du côté de Bob seulement
        $this->clock->sleep(60);
        $this->messaging()->clear($bobs);
        self::assertSame([], $this->repository()->findPlacesOf($bob));
        self::assertCount(1, $this->repository()->findPlacesOf($ana));
        $this->clock->sleep(60);
        $this->messaging()->reply($conversation, $ana, 'Troisième');
        self::assertCount(1, $this->repository()->findPlacesOf($bob));
        self::assertSame(['Troisième'], $this->visibleBodies($conversation, $bob));
    }

    public function testInvalidMessagesAreRefused(): void
    {
        [$ana] = $this->empires('Ana');

        foreach ([
            [['Personne'], 'Bonjour', 'Aucun joueur ne s’appelle « Personne ».'],
            [[''], 'Bonjour', 'Indiquez au moins un destinataire.'],
            [['Ana'], 'Bonjour', 'Indiquez au moins un destinataire.'],
        ] as [$recipients, $body, $error]) {
            try {
                $this->messaging()->start($ana, $recipients, null, $body);
                self::fail('Envoi accepté à tort.');
            } catch (InvalidMessage $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
        [, $bob] = $this->empires('Abe', 'Bea');
        $this->expectExceptionObject(new InvalidMessage('Le message est vide.'));
        $this->messaging()->start($ana, [$bob->getName()], null, '   ');
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(fn(string $name): Empire => EmpireFactory::createOne(['name' => $name, 'foundedAt' => $this->clock->now()]), array_values($names));
    }

    private function place(Conversation $conversation, Empire $empire): \App\Entity\ConversationParticipant
    {
        $place = $conversation->participantOf($empire);
        \assert(null !== $place);

        return $place;
    }

    /** @return list<string> */
    private function visibleBodies(Conversation $conversation, Empire $empire): array
    {
        $place = $this->place($conversation, $empire);
        $bodies = [];
        foreach ($conversation->getMessages() as $message) {
            if ($place->sees($message)) {
                $bodies[] = $message->getBody();
            }
        }

        return $bodies;
    }

    private function messaging(): Messaging
    {
        return self::getContainer()->get(Messaging::class);
    }

    private function repository(): ConversationRepository
    {
        return self::getContainer()->get(ConversationRepository::class);
    }
}
