<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Empire;
use App\Enum\Account\EmpireKind;
use App\Enum\Admin\AdminRole;
use App\Repository\EmpireRepository;
use App\Service\Admin\EntityHistory;
use App\Service\Admin\Moderation;
use App\Service\Admin\PlayerFiles;
use App\Service\Admin\ProgressCharts;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Joueurs (§5.6.1) : liste et recherche (empire, adresse e-mail), fiche empire avec planètes, ressources,
 * bâtiments et journal d'activité. Un joueur est identifié par son empire, créé avec son compte à l'inscription.
 * Consultation seule : les actions de modération arrivent avec le back-office de modération (#78).
 *
 * @extends AbstractCrudController<Empire>
 */
#[AdminRoute(path: '/joueurs', name: 'player')]
final class PlayerCrudController extends AbstractCrudController
{
    use HistoryActionTrait;
    use SameOriginTrait;

    public function __construct(
        private readonly PlayerFiles $files,
        private readonly Moderation $moderation,
        private readonly EmpireRepository $empires,
        private readonly ProgressCharts $progress,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Empire::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Joueur')
            ->setEntityLabelInPlural('Joueurs')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Empire $empire): string => \sprintf('%s — %s', $empire->getName(), $empire->getUser()->getEmail()))
            ->setDefaultSort(['foundedAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->overrideTemplate('crud/detail', 'admin/player/detail.html.twig');
    }

    /** Joueurs seulement : les civilisations non-joueuses (§4.14) ont leur propre suivi */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.kind = :player')
            ->setParameter('player', EmpireKind::Player);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::Moderator->value)
            ->setPermission(Action::DETAIL, AdminRole::Moderator->value);

        return $this->addHistoryAction($actions, 'empire');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('foundedAt', 'Inscription'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Empire');
        yield TextField::new('user.email', 'Adresse e-mail');
        yield IntegerField::new('score', 'Score');
        yield ChoiceField::new('orientation', 'Orientation')->hideOnIndex();
        yield DateTimeField::new('foundedAt', 'Inscription')->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('user.lastActiveAt', 'Dernière activité')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->formatValue(static fn(mixed $value): string => $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : 'Jamais');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $empire = $responseParameters->get('entity')->getInstance();
            \assert($empire instanceof Empire);
            $responseParameters->set('player', $this->files->of($empire));
            // Suivi de la progression : 30 derniers jours d'instantanés
            $responseParameters->set('progress_charts', $this->progress->of($empire));
            $responseParameters->set('progress_days', ProgressCharts::DEFAULT_DAYS);
            $responseParameters->set('history_types', EntityHistory::TYPE_LABELS);
        }

        return $responseParameters;
    }

    /** Sanctions de la modération (§4.13) : avertir, mettre le chat en sourdine, bannir, lever un bannissement */
    #[AdminRoute(path: '/{entityId}/moderer/{operation}', name: 'moderate', options: ['methods' => ['POST'], 'requirements' => ['operation' => 'avertir|sourdine|bannir|lever']])]
    public function moderate(int $entityId, string $operation, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::Moderator->value);
        $this->denyUnlessSameOrigin($request);
        $empire = $this->empires->find($entityId) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('moderate-' . $entityId, $request->request->getString('_token'))) {
            $this->addFlash('danger', 'La page a expiré : recommencez.');
        } else {
            try {
                match ($operation) {
                    'avertir' => $this->moderation->warn($empire, $request->request->getString('message')),
                    'sourdine' => $this->moderation->muteChat($empire, $request->request->getInt('hours')),
                    'bannir' => $this->moderation->ban($empire, '' === $request->request->getString('days') ? null : $request->request->getInt('days'), $request->request->getString('reason')),
                    default => $this->moderation->liftBan($empire),
                };
                $this->addFlash('success', match ($operation) {
                    'avertir' => 'Avertissement envoyé.',
                    'sourdine' => 'Chat mis en sourdine.',
                    'bannir' => 'Joueur banni.',
                    default => 'Bannissement levé.',
                });
            } catch (\DomainException $exception) {
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($entityId)->generateUrl());
    }
}
