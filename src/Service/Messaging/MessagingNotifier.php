<?php

declare(strict_types=1);

namespace App\Service\Messaging;

use App\Entity\Message;
use App\Model\Scheduling\GameTopics;
use App\Repository\ConversationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

/**
 * Temps réel de la messagerie (§5.7) : à chaque nouveau message, un Turbo Stream sur le topic personnel de chaque
 * participant présent met à jour la conversation ouverte, la liste des conversations et le compteur de non lus. Un
 * échec de publication n'annule pas l'envoi : la page se met à jour au prochain affichage.
 */
final readonly class MessagingNotifier
{
    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
        private ConversationRepository $conversations,
        private LoggerInterface $logger,
    ) {}

    public function posted(Message $message): void
    {
        foreach ($message->getConversation()->activeParticipants() as $place) {
            $empire = $place->getEmpire();
            try {
                $this->hub->publish(new Update(
                    GameTopics::empire($empire),
                    $this->twig->render('streams/message_posted.stream.html.twig', [
                        'message' => $message,
                        'place' => $place,
                        'viewer' => $empire,
                        'unread' => $this->conversations->countUnread($empire),
                    ]),
                    private: true,
                ));
            } catch (\Throwable $exception) {
                $this->logger->warning('Notification de message impossible (empire #{id}).', ['id' => $empire->getId(), 'exception' => $exception]);
            }
        }
    }
}
