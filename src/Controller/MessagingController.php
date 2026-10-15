<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationParticipant;
use App\Entity\Empire;
use App\Entity\Message;
use App\Enum\Messaging\MessageCategory;
use App\Exception\Messaging\InvalidMessage;
use App\Exception\Moderation\ReportRefused;
use App\Repository\ConversationRepository;
use App\Repository\PlayerIgnoreRepository;
use App\Service\Account\GameContext;
use App\Service\Messaging\IgnoredPlayers;
use App\Service\Messaging\Messaging;
use App\Service\Moderation\MessageReports;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran « Messagerie » (§4.12.2, §5.7) : conversations de l'empire (boîte et archives, Turbo Frame), nouvelle
 * conversation, conversation ouverte (lue à l'affichage) avec réponse, ajout de participants, départ, archivage et
 * suppression de son côté. La rédaction passe par le Live Component « MessageComposer ». Liste des joueurs ignorés
 * (§4.12.4), commune au chat et à la messagerie.
 */
final class MessagingController extends AbstractController
{
    public function __construct(
        private readonly GameContext $context,
        private readonly ConversationRepository $conversations,
        private readonly Messaging $messaging,
        private readonly IgnoredPlayers $ignored,
        private readonly PlayerIgnoreRepository $ignores,
        private readonly MessageReports $reports,
    ) {}

    public const string PLAYERS_FOLDER = 'joueurs';

    #[Route('/messagerie', name: 'app_messaging', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $empire = $this->empire();
        $archived = $request->query->getBoolean('archives');
        // Dossier : tout, les conversations entre joueurs, ou une catégorie de messages du jeu (§4.12.2)
        $folder = $request->query->getString('categorie');
        $category = MessageCategory::tryFrom($folder);
        $players = self::PLAYERS_FOLDER === $folder;

        return $this->render('messaging/index.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'archived' => $archived,
            'folder' => null !== $category || $players ? $folder : null,
            'categories' => MessageCategory::cases(),
            'players_folder' => self::PLAYERS_FOLDER,
            'places' => $this->conversations->findPlacesOf($empire, $archived, category: $category, playersOnly: $players),
        ]);
    }

    #[Route('/messagerie/nouvelle', name: 'app_messaging_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $empire = $this->empire();

        return $this->render('messaging/new.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'recipients' => $request->query->getString('a'),
        ]);
    }

    #[Route('/messagerie/{id}', name: 'app_messaging_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        [$empire, $conversation, $place] = $this->place($id);
        $this->messaging->markRead($place);
        // Messages des joueurs ignorés masqués (§4.12.4)
        $ignored = $this->ignored->idsOf($empire);
        $visible = static fn(Message $message): bool => $place->sees($message) && !\in_array($message->getAuthor()?->getId(), $ignored, true);

        return $this->render('messaging/show.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'conversation' => $conversation,
            'place' => $place,
            'messages' => array_values(array_filter($conversation->getMessages()->toArray(), $visible)),
            'ignored' => $ignored,
        ]);
    }

    #[Route('/messagerie/ignores', name: 'app_messaging_ignored', methods: ['GET'])]
    public function ignored(): Response
    {
        $empire = $this->empire();

        return $this->render('messaging/ignored.html.twig', [
            'empire' => $empire,
            'planet' => $empire->getActivePlanet(),
            'ignores' => $this->ignores->findOf($empire),
        ]);
    }

    #[Route('/messagerie/ignores', name: 'app_messaging_ignore', methods: ['POST'])]
    public function ignore(Request $request): Response
    {
        $empire = $this->empire();

        return $this->ignoreAction($request, fn(): string => \sprintf('Vous ignorez désormais %s.', $this->ignored->ignore($empire, $request->request->getString('name'))->getName()));
    }

    #[Route('/messagerie/ignores/{id}/retirer', name: 'app_messaging_unignore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unignore(int $id, Request $request): Response
    {
        $empire = $this->empire();

        return $this->ignoreAction($request, fn(): string => \sprintf('Vous n’ignorez plus %s.', $this->ignored->unignore($empire, $id)->getName()));
    }

    /** @param callable(): string $action */
    private function ignoreAction(Request $request, callable $action): Response
    {
        if (!$this->isCsrfTokenValid('messaging-ignore', $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action());
            } catch (InvalidMessage $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_messaging_ignored');
    }

    /** Signale un message reçu à la modération (§4.13) */
    #[Route('/messagerie/messages/{id}/signaler', name: 'app_messaging_report', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function report(Message $message, Request $request): Response
    {
        $empire = $this->empire();
        $conversation = $message->getConversation();
        if (!$this->isCsrfTokenValid('report-message-' . $message->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->reports->reportMessage($empire, $message, $request->request->getString('reason'));
                $this->addFlash('success', 'Signalement transmis à la modération.');
            } catch (ReportRefused $refusal) {
                $this->addFlash('error', $refusal->getMessage());
            }
        }

        return $this->redirectToRoute('app_messaging_show', ['id' => $conversation->getId()]);
    }

    #[Route('/messagerie/{id}/participants', name: 'app_messaging_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(int $id, Request $request): Response
    {
        [$empire, $conversation] = $this->place($id);

        return $this->act($request, $conversation, 'add', function () use ($conversation, $empire, $request): string {
            $added = $this->messaging->addParticipants($conversation, $empire, explode(',', $request->request->getString('names')));

            return \sprintf('Ajouté(s) à la conversation : %s.', implode(', ', array_map(static fn(Empire $added): string => $added->getName(), $added)));
        });
    }

    #[Route('/messagerie/{id}/quitter', name: 'app_messaging_leave', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function leave(int $id, Request $request): Response
    {
        [$empire, $conversation] = $this->place($id);

        return $this->act($request, $conversation, 'leave', function () use ($conversation, $empire): string {
            $this->messaging->leave($conversation, $empire);

            return 'Vous avez quitté la conversation.';
        }, toList: true);
    }

    #[Route('/messagerie/{id}/archiver', name: 'app_messaging_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id, Request $request): Response
    {
        [, $conversation, $place] = $this->place($id);
        $archived = !$place->isArchived();

        return $this->act($request, $conversation, 'archive', function () use ($place, $archived): string {
            $this->messaging->archive($place, $archived);

            return $archived ? 'Conversation archivée.' : 'Conversation remise dans la boîte.';
        }, toList: true);
    }

    #[Route('/messagerie/{id}/supprimer', name: 'app_messaging_clear', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clear(int $id, Request $request): Response
    {
        [, $conversation, $place] = $this->place($id);

        return $this->act($request, $conversation, 'clear', function () use ($place): string {
            $this->messaging->clear($place);

            return 'Conversation supprimée de votre côté.';
        }, toList: true);
    }

    /** @param callable(): string $action */
    private function act(Request $request, Conversation $conversation, string $name, callable $action, bool $toList = false): Response
    {
        if (!$this->isCsrfTokenValid(\sprintf('messaging-%s-%d', $name, $conversation->getId()), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : recommencez.');
        } else {
            try {
                $this->addFlash('success', $action());
            } catch (InvalidMessage $exception) {
                $this->addFlash('error', $exception->getMessage());
                $toList = false;
            }
        }

        return $toList ? $this->redirectToRoute('app_messaging') : $this->redirectToRoute('app_messaging_show', ['id' => $conversation->getId()]);
    }

    private function empire(): Empire
    {
        return $this->context->empire() ?? throw $this->createAccessDeniedException();
    }

    /** @return array{Empire, Conversation, ConversationParticipant} */
    private function place(int $id): array
    {
        $empire = $this->empire();
        $conversation = $this->conversations->find($id);
        $place = $conversation?->participantOf($empire);
        if (null === $conversation || null === $place) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        return [$empire, $conversation, $place];
    }
}
