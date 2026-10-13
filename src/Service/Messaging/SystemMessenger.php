<?php

declare(strict_types=1);

namespace App\Service\Messaging;

use App\Entity\Alliance;
use App\Entity\AllianceMember;
use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Empire;
use App\Entity\Message;
use App\Enum\Messaging\SystemAudience;
use App\Exception\Messaging\InvalidMessage;
use App\Message\DeliverSystemMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Messages système (§4.12.2, §5.7) : service unique du code de jeu et du back-office. Un message du jeu est une
 * conversation système (auteur nul, sans réponse, destinataires invisibles entre eux) ; une poignée de destinataires
 * est servie aussitôt, une alliance ou tous les joueurs le sont en asynchrone, par lots (DeliverSystemMessage).
 */
final readonly class SystemMessenger
{
    /** Destinataires servis dans la requête ; au-delà, la livraison passe par la file asynchrone */
    public const int IMMEDIATE_LIMIT = 50;

    /** Destinataires ajoutés par lot lors d'une livraison asynchrone */
    public const int BATCH = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessagingNotifier $notifier,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidMessage */
    public function toEmpire(Empire $empire, ?string $subject, string $body): Conversation
    {
        return $this->toEmpires([$empire], $subject, $body);
    }

    /**
     * @param list<Empire> $empires
     *
     * @throws InvalidMessage
     */
    public function toEmpires(array $empires, ?string $subject, string $body): Conversation
    {
        $empires = array_values(array_unique($empires, \SORT_REGULAR));
        if ([] === $empires) {
            throw new InvalidMessage('Indiquez au moins un destinataire.');
        }
        $conversation = $this->open($subject, $body);
        if (\count($empires) <= self::IMMEDIATE_LIMIT) {
            $this->deliver($conversation, $empires);
        } else {
            $this->bus->dispatch(new DeliverSystemMessage((int) $conversation->getId(), SystemAudience::Empires, empireIds: array_map(static fn(Empire $empire): int => (int) $empire->getId(), $empires)));
        }

        return $conversation;
    }

    /**
     * Membres de l'alliance au moment de la livraison.
     *
     * @throws InvalidMessage
     */
    public function toAlliance(Alliance $alliance, ?string $subject, string $body): Conversation
    {
        $conversation = $this->open($subject, $body);
        $this->bus->dispatch(new DeliverSystemMessage((int) $conversation->getId(), SystemAudience::Alliance, (int) $alliance->getId()));

        return $conversation;
    }

    /** @throws InvalidMessage */
    public function toAll(?string $subject, string $body): Conversation
    {
        $conversation = $this->open($subject, $body);
        $this->bus->dispatch(new DeliverSystemMessage((int) $conversation->getId(), SystemAudience::All));

        return $conversation;
    }

    /**
     * Livraison d'un envoi asynchrone : les destinataires de l'audience, par lots ; chaque lot est enregistré puis
     * annoncé en temps réel. Rejouée, elle ne sert pas deux fois le même destinataire.
     */
    public function deliverAsync(DeliverSystemMessage $delivery): int
    {
        $delivered = 0;
        $afterId = 0;
        do {
            $conversation = $this->entityManager->find(Conversation::class, $delivery->conversationId);
            if (!$conversation instanceof Conversation) {
                // Envoi pas encore validé (transaction de l'appelant en cours) : nouvelle tentative plus tard
                throw new RecoverableMessageHandlingException(\sprintf('Conversation système #%d introuvable.', $delivery->conversationId));
            }
            $ids = $this->audienceBatch($delivery, $afterId);
            if ([] !== $ids) {
                $afterId = max($ids);
                $empires = $this->entityManager->getRepository(Empire::class)->findBy(['id' => $ids]);
                $delivered += \count($this->deliver($conversation, $empires));
            }
            $this->entityManager->clear();
        } while (\count($ids) === self::BATCH);

        return $delivered;
    }

    /** @throws InvalidMessage */
    private function open(?string $subject, string $body): Conversation
    {
        $body = trim($body);
        if ('' === $body) {
            throw new InvalidMessage('Le message est vide.');
        }
        if (mb_strlen($body) > Message::BODY_MAX_LENGTH) {
            throw new InvalidMessage(\sprintf('Un message compte au plus %d caractères.', Message::BODY_MAX_LENGTH));
        }
        $subject = null === $subject ? null : trim($subject);
        if (null !== $subject && mb_strlen($subject) > Conversation::SUBJECT_MAX_LENGTH) {
            throw new InvalidMessage(\sprintf('L’objet compte au plus %d caractères.', Conversation::SUBJECT_MAX_LENGTH));
        }
        $now = $this->clock->now();
        $conversation = new Conversation(false, '' === $subject ? null : $subject, $now, system: true);
        $conversation->post(null, $body, $now);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        return $conversation;
    }

    /**
     * Ajoute les destinataires (qui voient le message depuis son envoi) et les prévient.
     *
     * @param list<Empire> $empires
     *
     * @return list<ConversationParticipant> places créées
     */
    private function deliver(Conversation $conversation, array $empires): array
    {
        $message = $conversation->getMessages()->first();
        \assert($message instanceof Message);
        $places = [];
        foreach ($empires as $empire) {
            if (null === $conversation->participantOf($empire)) {
                $places[] = $conversation->add($empire, $message->getSentAt());
            }
        }
        $this->entityManager->flush();
        $this->notifier->deliveredTo($message, $places);

        return $places;
    }

    /** @return list<int> identifiants d'empires suivants de l'audience, au plus BATCH */
    private function audienceBatch(DeliverSystemMessage $delivery, int $afterId): array
    {
        $query = match ($delivery->audience) {
            SystemAudience::All => $this->entityManager->createQueryBuilder()->select('e.id')->from(Empire::class, 'e'),
            SystemAudience::Alliance => $this->entityManager->createQueryBuilder()->select('e.id')->from(AllianceMember::class, 'm')->join('m.empire', 'e')
                ->andWhere('IDENTITY(m.alliance) = :alliance')->setParameter('alliance', $delivery->allianceId),
            SystemAudience::Empires => $this->entityManager->createQueryBuilder()->select('e.id')->from(Empire::class, 'e')
                ->andWhere('e.id IN (:ids)')->setParameter('ids', [] === $delivery->empireIds ? [0] : $delivery->empireIds),
        };
        $query->andWhere('e.id > :after')
            ->setParameter('after', $afterId)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(self::BATCH);

        return array_map('intval', $query->getQuery()->getSingleColumnResult());
    }
}
