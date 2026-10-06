<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Service\Admin\AdminAudit;
use App\Service\Admin\MessengerSupervision;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Supervision des files Messenger (§5.6.1, section Exploitation) : messages en attente et en échec ; relance ou
 * suppression d'un message en échec, inscrites au journal des actions (§5.6.2).
 * Routes : admin_messenger_index, _retry, _remove.
 */
#[IsGranted(AdminRole::Admin->value)]
#[AdminRoute(path: '/files-messages', name: 'messenger')]
final class MessengerQueueController extends AbstractController
{
    use SameOriginTrait;

    public function __construct(
        private readonly MessengerSupervision $supervision,
        private readonly AdminAudit $audit,
    ) {}

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->render('admin/messenger/index.html.twig', [
            'pending_count' => $this->supervision->count(MessengerSupervision::PENDING_TRANSPORT),
            'pending' => $this->supervision->list(MessengerSupervision::PENDING_TRANSPORT),
            'failed_count' => $this->supervision->count(MessengerSupervision::FAILED_TRANSPORT),
            'failed' => $this->supervision->list(MessengerSupervision::FAILED_TRANSPORT),
            'limit' => MessengerSupervision::LIST_LIMIT,
        ]);
    }

    #[AdminRoute(path: '/{id}/relancer', name: 'retry', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function retry(string $id, Request $request): Response
    {
        if ($this->acceptsAction($request, 'retry', $id)) {
            $message = $this->supervision->retry($id);
            $this->audit->record(AuditAction::Retry, $message, ['error' => [$message->error, null]]);
            $this->addFlash('success', \sprintf('%s renvoyé dans la file « %s ».', $message, $message->originalTransport ?? MessengerSupervision::PENDING_TRANSPORT));
        }

        return $this->redirectToRoute('admin_messenger_index');
    }

    #[AdminRoute(path: '/{id}/supprimer', name: 'remove', options: ['methods' => ['POST'], 'requirements' => ['id' => '\d+']])]
    public function remove(string $id, Request $request): Response
    {
        if ($this->acceptsAction($request, 'remove', $id)) {
            $message = $this->supervision->remove($id);
            $this->audit->record(AuditAction::Discard, $message, ['error' => [$message->error, null]]);
            $this->addFlash('success', \sprintf('%s supprimé.', $message));
        }

        return $this->redirectToRoute('admin_messenger_index');
    }

    /** Jeton CSRF, origine, et message encore présent (un autre administrateur a pu le traiter entre-temps) */
    private function acceptsAction(Request $request, string $action, string $id): bool
    {
        $this->denyUnlessSameOrigin($request);
        if (!$this->isCsrfTokenValid(\sprintf('admin_messenger_%s_%s', $action, $id), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La page a expiré : recommencez.');

            return false;
        }
        if (null === $this->supervision->findFailed($id)) {
            $this->addFlash('warning', \sprintf('Le message en échec #%s n’existe plus.', $id));

            return false;
        }

        return true;
    }
}
