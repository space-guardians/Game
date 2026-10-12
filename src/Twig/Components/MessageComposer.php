<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Exception\Messaging\InvalidMessage;
use App\Repository\ConversationRepository;
use App\Service\Account\GameContext;
use App\Service\Messaging\Messaging;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Rédaction d'un message (§5.7, Live Component) : nouvelle conversation (destinataires, objet) ou réponse dans une
 * conversation ouverte. Le refus (destinataire inconnu, message vide…) s'affiche sans recharger la page ; l'envoi mène
 * à la conversation, où le message arrive aussi en temps réel chez les autres participants.
 */
#[AsLiveComponent]
final class MessageComposer extends AbstractController
{
    use DefaultActionTrait;

    /** Conversation à laquelle on répond ; null pour une nouvelle conversation */
    #[LiveProp]
    public ?int $conversationId = null;

    /** Destinataires, noms d'empires séparés par des virgules */
    #[LiveProp(writable: true)]
    public string $recipients = '';

    #[LiveProp(writable: true)]
    public string $subject = '';

    #[LiveProp(writable: true)]
    public string $body = '';

    public ?string $error = null;

    public function __construct(
        private readonly GameContext $context,
        private readonly ConversationRepository $conversations,
        private readonly Messaging $messaging,
    ) {}

    public function getMaxLength(): int
    {
        return Message::BODY_MAX_LENGTH;
    }

    public function getSubjectMaxLength(): int
    {
        return Conversation::SUBJECT_MAX_LENGTH;
    }

    #[LiveAction]
    public function send(): ?Response
    {
        $empire = $this->context->empire();
        if (null === $empire) {
            throw $this->createAccessDeniedException();
        }

        try {
            if (null === $this->conversationId) {
                $conversation = $this->messaging->start($empire, explode(',', $this->recipients), $this->subject, $this->body);
            } else {
                $conversation = $this->conversations->find($this->conversationId);
                if (null === $conversation || null === $conversation->participantOf($empire)) {
                    throw $this->createNotFoundException('Conversation introuvable.');
                }
                $this->messaging->reply($conversation, $empire, $this->body);
            }
        } catch (InvalidMessage $exception) {
            $this->error = $exception->getMessage();

            return null;
        }

        return $this->redirectToRoute('app_messaging_show', ['id' => $conversation->getId(), '_fragment' => 'dernier']);
    }
}
