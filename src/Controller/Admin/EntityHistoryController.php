<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditOrigin;
use App\Model\Admin\AuditedEntity;
use App\Model\Admin\HistoryFilters;
use App\Service\Admin\EntityHistory;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Historique des modifications des données (§5.6.3), en consultation seule, intégré au panneau.
 * Routes : admin_entity_history_index, _list, _entry.
 */
#[IsGranted(AdminRole::Admin->value)]
#[AdminRoute(path: '/historique', name: 'entity_history')]
final class EntityHistoryController extends AbstractController
{
    public function __construct(
        private readonly EntityHistory $history,
    ) {}

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        $entities = array_map(
            fn(AuditedEntity $entity): array => ['entity' => $entity, ...$this->history->summary($entity)],
            $this->history->entities(),
        );

        return $this->render('admin/history/index.html.twig', ['entities' => $entities]);
    }

    #[AdminRoute(path: '/{entity}', name: 'list', options: ['methods' => ['GET'], 'requirements' => ['entity' => '[a-z_]+']])]
    public function list(string $entity, Request $request): Response
    {
        $audited = $this->audited($entity);
        $filters = HistoryFilters::fromQuery($request->query);

        return $this->render('admin/history/list.html.twig', [
            'audited' => $audited,
            'filters' => $filters,
            'result' => $this->history->search($audited, $filters, $request->query->getInt('page', 1)),
            'types' => EntityHistory::TYPE_LABELS,
            'origins' => AuditOrigin::cases(),
        ]);
    }

    #[AdminRoute(path: '/{entity}/{id}', name: 'entry', options: ['methods' => ['GET'], 'requirements' => ['entity' => '[a-z_]+', 'id' => '\d+']])]
    public function entry(string $entity, int $id): Response
    {
        $audited = $this->audited($entity);
        $entry = $this->history->find($audited, $id) ?? throw new NotFoundHttpException('Entrée d’historique introuvable.');

        return $this->render('admin/history/entry.html.twig', [
            'audited' => $audited,
            'entry' => $entry,
            'types' => EntityHistory::TYPE_LABELS,
            'transaction' => null === $entry->transactionHash ? [] : $this->history->transaction($entry->transactionHash),
        ]);
    }

    private function audited(string $key): AuditedEntity
    {
        return $this->history->entity($key) ?? throw new NotFoundHttpException('Cette entité n’est pas historisée.');
    }
}
