<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Empire;
use App\Exception\Chat\ChatRecipientOffline;
use App\Exception\Chat\ChatRefused;
use App\Repository\EmpireRepository;
use App\Service\Account\GameContext;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatPresence;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chat en ligne (§4.12.1, §5.7) : envoi d'un message (POST JSON, jeton CSRF « chat » en en-tête) et fil récent d'un
 * canal, pour le widget. Le hub Mercure ne fait que diffuser ce que ce contrôleur a accepté.
 */
final class ChatController extends AbstractController
{
    public const string CSRF_HEADER = 'X-CSRF-Token';

    /** Joueurs en ligne nommés dans l'indicateur de présence */
    public const int PRESENCE_SHOWN = 30;

    public function __construct(
        private readonly GameContext $context,
        private readonly Chat $chat,
        private readonly ChatPresence $presence,
        private readonly EmpireRepository $empires,
    ) {}

    #[Route('/chat/messages', name: 'app_chat_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $empire = $this->empire();
        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get(self::CSRF_HEADER))) {
            return $this->json(['error' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];

        try {
            $message = $this->chat->post($empire, (string) ($payload['channel'] ?? ''), (string) ($payload['body'] ?? ''));
        } catch (ChatRefused $refusal) {
            return $this->json(['error' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ChatRecipientOffline $offline) {
            return $this->offline($offline);
        }

        return $this->json($message, Response::HTTP_CREATED);
    }

    /** Ouvre un onglet de discussion privée avec un joueur connecté */
    #[Route('/chat/prive', name: 'app_chat_private', methods: ['POST'])]
    public function openPrivate(Request $request): JsonResponse
    {
        $empire = $this->empire();
        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get(self::CSRF_HEADER))) {
            return $this->json(['error' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);

        try {
            return $this->json($this->chat->openPrivate($empire, (string) (\is_array($payload) ? ($payload['name'] ?? '') : '')));
        } catch (ChatRefused $refusal) {
            return $this->json(['error' => $refusal->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ChatRecipientOffline $offline) {
            return $this->offline($offline);
        }
    }

    /** Interlocuteur hors ligne : refus, avec le lien vers un nouveau message dans la messagerie */
    private function offline(ChatRecipientOffline $offline): JsonResponse
    {
        return $this->json([
            'error' => $offline->getMessage(),
            'messaging' => $this->generateUrl('app_messaging_new', ['a' => $offline->recipient->getName()]),
        ], Response::HTTP_CONFLICT);
    }

    /**
     * Battement de cœur du widget (§5.7) : le joueur est en ligne ; renvoie les joueurs en ligne pour l'indicateur de
     * présence.
     */
    #[Route('/chat/presence', name: 'app_chat_presence', methods: ['POST'])]
    public function presence(Request $request): JsonResponse
    {
        $empire = $this->empire();
        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get(self::CSRF_HEADER))) {
            return $this->json(['error' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $this->presence->heartbeat($empire);
        $ids = $this->presence->onlineIds();
        $shown = $this->empires->findBy(['id' => \array_slice($ids, 0, self::PRESENCE_SHOWN)], ['name' => 'ASC']);

        return $this->json([
            'count' => \count($ids),
            'players' => array_map(static fn(Empire $online): array => ['id' => $online->getId(), 'name' => $online->getName()], $shown),
            'interval' => ChatPresence::HEARTBEAT_SECONDS,
        ]);
    }

    #[Route('/chat/messages', name: 'app_chat_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $empire = $this->empire();

        try {
            return $this->json($this->chat->recent($empire, $request->query->getString('canal', Chat::PUBLIC_CHANNEL)));
        } catch (ChatRefused $refusal) {
            return $this->json(['error' => $refusal->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    private function empire(): Empire
    {
        return $this->context->empire() ?? throw $this->createAccessDeniedException();
    }
}
