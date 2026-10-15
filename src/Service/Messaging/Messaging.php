<?php

declare(strict_types=1);

namespace App\Service\Messaging;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Empire;
use App\Entity\Message;
use App\Enum\Notification\NotificationType;
use App\Exception\Messaging\InvalidMessage;
use App\Repository\ConversationRepository;
use App\Repository\EmpireRepository;
use App\Service\Notification\Notifications;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Messagerie entre joueurs (§4.12.2, §5.7) : conversations à deux (réutilisées si elles existent déjà) ou de groupe,
 * réponses, ajout de participants, départ, lecture, archivage et suppression de son côté. Chaque nouveau message est
 * annoncé en temps réel à ses participants (MessagingNotifier).
 */
final readonly class Messaging
{
    /** Destinataires au plus d'une nouvelle conversation, ou ajoutés d'un coup */
    public const int MAX_RECIPIENTS = 20;

    public function __construct(
        private ConversationRepository $conversations,
        private EmpireRepository $empires,
        private EntityManagerInterface $entityManager,
        private MessagingNotifier $notifier,
        private ClockInterface $clock,
        private IgnoredPlayers $ignored,
        private Notifications $notifications,
    ) {}

    /**
     * Nouveau message vers un ou plusieurs empires, désignés par leur nom : à un seul destinataire, la conversation à
     * deux existante est reprise ; à plusieurs, une conversation de groupe est créée.
     *
     * @param list<string> $recipientNames
     *
     * @throws InvalidMessage
     */
    public function start(Empire $author, array $recipientNames, ?string $subject, string $body): Conversation
    {
        $recipients = $this->resolve($author, $recipientNames);
        if ([] === $recipients) {
            throw new InvalidMessage('Indiquez au moins un destinataire.');
        }
        foreach ($recipients as $recipient) {
            $this->guardRecipient($author, $recipient);
        }
        $body = $this->checkBody($body);
        $subject = null === $subject ? null : trim($subject);
        if (null !== $subject && mb_strlen($subject) > Conversation::SUBJECT_MAX_LENGTH) {
            throw new InvalidMessage(\sprintf('L’objet compte au plus %d caractères.', Conversation::SUBJECT_MAX_LENGTH));
        }
        $now = $this->clock->now();

        $conversation = 1 === \count($recipients) ? $this->conversations->findDirectBetween($author, $recipients[0]) : null;
        if (null === $conversation) {
            $conversation = new Conversation(\count($recipients) > 1, '' === $subject ? null : $subject, $now);
            $conversation->add($author, $now);
            foreach ($recipients as $recipient) {
                $conversation->add($recipient, $now);
            }
            $this->entityManager->persist($conversation);
        }

        $this->post($conversation, $author, $body, $now);

        return $conversation;
    }

    /** @throws InvalidMessage */
    public function reply(Conversation $conversation, Empire $author, string $body): Message
    {
        $this->activePlace($conversation, $author);
        if (!$conversation->isGroup()) {
            // À deux : un joueur ignoré n'écrit plus à celui qui l'ignore (§4.12.4)
            foreach ($conversation->activeParticipants() as $place) {
                if ($place->getEmpire() !== $author) {
                    $this->guardRecipient($author, $place->getEmpire());
                }
            }
        }

        return $this->post($conversation, $author, $this->checkBody($body), $this->clock->now());
    }

    /**
     * Ajoute des participants à une conversation de groupe ; ils voient les messages à partir de leur arrivée.
     *
     * @param list<string> $names
     *
     * @return list<Empire> empires ajoutés
     *
     * @throws InvalidMessage
     */
    public function addParticipants(Conversation $conversation, Empire $by, array $names): array
    {
        if (!$conversation->isGroup()) {
            throw new InvalidMessage('On n’ajoute des participants qu’à une conversation de groupe.');
        }
        $this->activePlace($conversation, $by);
        $added = [];
        $now = $this->clock->now();
        foreach ($this->resolve($by, $names) as $empire) {
            if (!($conversation->participantOf($empire)?->isActive() ?? false)) {
                if ($this->ignored->blocks($empire, $by)) {
                    throw new InvalidMessage(\sprintf('%s n’accepte pas d’être ajouté par vous.', $empire->getName()));
                }
                $conversation->add($empire, $now);
                $added[] = $empire;
            }
        }
        if ([] === $added) {
            throw new InvalidMessage('Ces joueurs participent déjà à la conversation.');
        }
        $this->entityManager->flush();

        return $added;
    }

    /** @throws InvalidMessage */
    public function leave(Conversation $conversation, Empire $empire): void
    {
        if (!$conversation->isGroup()) {
            throw new InvalidMessage('On ne quitte qu’une conversation de groupe ; supprimez plutôt celle-ci de votre côté.');
        }
        $this->activePlace($conversation, $empire)->leave($this->clock->now());
        $this->entityManager->flush();
    }

    public function markRead(ConversationParticipant $place): void
    {
        $place->markRead($this->clock->now());
        $this->entityManager->flush();
    }

    public function archive(ConversationParticipant $place, bool $archived): void
    {
        $place->archive($archived);
        $this->entityManager->flush();
    }

    /** Suppression de son côté uniquement : la conversation reste visible pour les autres */
    public function clear(ConversationParticipant $place): void
    {
        $place->clear($this->clock->now());
        $this->entityManager->flush();
    }

    public function unreadCount(Empire $empire): int
    {
        return $this->conversations->countUnread($empire);
    }

    private function post(Conversation $conversation, Empire $author, string $body, \DateTimeImmutable $at): Message
    {
        // Les participants qui ignorent l'auteur ne reçoivent pas son message (§4.12.4) : ni notification, ni retour
        // dans la boîte, et la conversation reste lue s'ils l'avaient lue jusqu'au bout
        $places = $conversation->activeParticipants();
        $ignoring = $this->ignored->ignoring($author, array_map(static fn(ConversationParticipant $place): int => (int) $place->getEmpire()->getId(), $places));
        $upToDate = array_filter($places, static fn(ConversationParticipant $place): bool => !$place->hasUnread());
        $message = $conversation->post($author, $body, $at);
        $recipients = [];
        foreach ($places as $place) {
            if (\in_array((int) $place->getEmpire()->getId(), $ignoring, true)) {
                if (\in_array($place, $upToDate, true)) {
                    $place->markRead($at);
                }
                continue;
            }
            // Un nouveau message ramène la conversation dans la boîte de chacun
            $place->archive(false);
            $recipients[] = $place;
        }
        // L'auteur l'a lue jusqu'à son message
        $conversation->participantOf($author)?->markRead($at);
        $this->entityManager->flush();
        foreach ($recipients as $place) {
            if ($place->getEmpire() !== $author) {
                $this->notifications->notify($place->getEmpire(), NotificationType::MessageReceived, \sprintf('Nouveau message de %s : %s', $author->getName(), $conversation->titleFor($place->getEmpire())), 'app_messaging_show', ['id' => $conversation->getId()]);
            }
        }
        $this->entityManager->flush();
        $this->notifier->deliveredTo($message, $recipients);

        return $message;
    }

    /** @throws InvalidMessage */
    private function activePlace(Conversation $conversation, Empire $empire): ConversationParticipant
    {
        if ($conversation->isSystem()) {
            throw new InvalidMessage('Un message du jeu n’appelle pas de réponse.');
        }
        $place = $conversation->participantOf($empire);
        if (null === $place || !$place->isActive()) {
            throw new InvalidMessage('Vous ne participez plus à cette conversation.');
        }

        return $place;
    }

    /** @throws InvalidMessage */
    private function guardRecipient(Empire $author, Empire $recipient): void
    {
        if ($this->ignored->blocks($recipient, $author)) {
            throw new InvalidMessage(\sprintf('%s n’accepte pas vos messages.', $recipient->getName()));
        }
    }

    /** @throws InvalidMessage */
    private function checkBody(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new InvalidMessage('Le message est vide.');
        }
        if (mb_strlen($body) > Message::BODY_MAX_LENGTH) {
            throw new InvalidMessage(\sprintf('Un message compte au plus %d caractères.', Message::BODY_MAX_LENGTH));
        }

        return $body;
    }

    /**
     * Empires désignés par leur nom (sans doublon ni soi-même).
     *
     * @param list<string> $names
     *
     * @return list<Empire>
     *
     * @throws InvalidMessage
     */
    private function resolve(Empire $author, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map(static fn(string $name): string => trim($name), $names), static fn(string $name): bool => '' !== $name)));
        if (\count($names) > self::MAX_RECIPIENTS) {
            throw new InvalidMessage(\sprintf('Au plus %d destinataires à la fois.', self::MAX_RECIPIENTS));
        }
        $empires = [];
        foreach ($names as $name) {
            $empire = $this->empires->findOneByName($name);
            if (null === $empire) {
                throw new InvalidMessage(\sprintf('Aucun joueur ne s’appelle « %s ».', $name));
            }
            if ($empire !== $author) {
                $empires[(int) $empire->getId()] = $empire;
            }
        }

        return array_values($empires);
    }
}
