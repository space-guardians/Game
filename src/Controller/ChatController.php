<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Empire;
use App\Exception\Chat\ChatRefused;
use App\Service\Account\GameContext;
use App\Service\Chat\Chat;
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

    public function __construct(
        private readonly GameContext $context,
        private readonly Chat $chat,
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
        }

        return $this->json($message, Response::HTTP_CREATED);
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
