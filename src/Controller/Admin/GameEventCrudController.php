<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GameEvent;
use App\Entity\Planet;
use App\Enum\Admin\AdminRole;
use App\Enum\Event\GameEventScope;
use App\Enum\Event\GameEventStatus;
use App\Service\Event\GameEvents;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Événements de jeu (§4.16, §5.6.1 « Quêtes & événements », rôle GameDesigner) : création, planification (début et fin
 * programmés à l'enregistrement, replanifiés à la modification), aperçu (planètes concernées), annulation. Un événement
 * ne se modifie que tant qu'il n'a pas commencé.
 *
 * @extends AbstractCrudController<GameEvent>
 */
#[AdminRoute(path: '/evenements-de-jeu', name: 'game_event')]
final class GameEventCrudController extends AbstractCrudController
{
    use HistoryActionTrait;
    use SameOriginTrait;

    public function __construct(
        private readonly GameEvents $events,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public static function getEntityFqcn(): string
    {
        return GameEvent::class;
    }

    public function createEntity(string $entityFqcn): GameEvent
    {
        $start = $this->clock->now()->modify('+1 hour')->setTime((int) $this->clock->now()->modify('+1 hour')->format('H'), 0);

        return new GameEvent($start, $start->modify('+2 days'));
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Événement de jeu')
            ->setEntityLabelInPlural('Événements de jeu')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(GameEvent $event): string => (string) $event)
            ->setDefaultSort(['startsAt' => 'DESC'])
            ->setSearchFields(['name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $cancel = Action::new('cancel', 'Annuler l’événement')
            ->linkToCrudAction('cancel')
            ->renderAsForm()
            ->askConfirmation('L’événement sera annulé : ses effets cessent aussitôt. Continuer ?')
            ->displayIf(static fn(GameEvent $event): bool => \in_array($event->getStatus(), [GameEventStatus::Scheduled, GameEventStatus::Running], true));

        $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $cancel)
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn(Action $action): Action => $action->displayIf(static fn(GameEvent $event): bool => GameEventStatus::Scheduled === $event->getStatus()))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn(Action $action): Action => $action->displayIf(static fn(GameEvent $event): bool => GameEventStatus::Scheduled === $event->getStatus()));
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER, 'cancel'] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'game_event');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextareaField::new('description', 'Description (reprise dans l’annonce)')->hideOnIndex();
        yield DateTimeField::new('startsAt', 'Début')->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('endsAt', 'Fin')->setFormat('dd/MM/yyyy HH:mm');
        yield ChoiceField::new('status', 'État')->hideOnForm();
        yield ChoiceField::new('scope', 'Portée')->setChoices(array_combine(array_map(static fn(GameEventScope $scope): string => $scope->label(), GameEventScope::cases()), GameEventScope::cases()));
        yield AssociationField::new('galaxy', 'Galaxie (galaxie ou zone)')->hideOnIndex();
        yield NumberField::new('zoneX', 'Centre de la zone : X')->hideOnIndex();
        yield NumberField::new('zoneY', 'Centre de la zone : Y')->hideOnIndex();
        yield NumberField::new('zoneRadius', 'Rayon de la zone')->hideOnIndex();
        yield IntegerField::new('productionBonus', 'Production (+ %)')->hideOnIndex();
        yield IntegerField::new('constructionBonus', 'Vitesse de construction (+ %)')->hideOnIndex();
        yield IntegerField::new('researchBonus', 'Vitesse de recherche (+ %)')->hideOnIndex();
        yield IntegerField::new('explorationBonus', 'Butin d’exploration (+ %)')->hideOnIndex();
        yield TextField::new('effectsLabel', 'Effets')->onlyOnIndex();
        // Aperçu : planètes d'empires touchées par l'événement
        yield IntegerField::new('id', 'Planètes concernées (aperçu)')->onlyOnDetail()->formatValue(fn(mixed $value, GameEvent $event): string => (string) $this->coveredPlanets($event));
    }

    /** @param GameEvent $entityInstance */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->events->schedule($entityInstance);
        $entityManager->flush();
    }

    /** @param GameEvent $entityInstance */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        // Dates peut-être changées : nouveaux réveils ; les anciens ne correspondent plus et restent sans effet
        $this->events->schedule($entityInstance);
        $entityManager->flush();
    }

    /**
     * @param AdminContext<GameEvent> $context
     */
    #[AdminRoute(path: '/{entityId}/annuler', name: 'cancel', options: ['methods' => ['POST']])]
    public function cancel(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::GameDesigner->value);
        $this->denyUnlessSameOrigin($request);
        $event = $context->getEntity()->getInstance();
        \assert($event instanceof GameEvent);
        $this->events->cancel($event);
        $this->entityManager->flush();
        $this->addFlash('success', \sprintf('Événement « %s » annulé.', $event));

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($event->getId())->generateUrl());
    }

    private function coveredPlanets(GameEvent $event): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Planet::class, 'p')
            ->join('p.system', 's')
            ->where('p.owner IS NOT NULL');
        if (null !== $event->getGalaxy() && GameEventScope::Universe !== $event->getScope()) {
            $query->andWhere('s.galaxy = :galaxy')->setParameter('galaxy', $event->getGalaxy());
        }
        /** @var list<Planet> $planets */
        $planets = $query->getQuery()->getResult();

        return \count(array_filter($planets, $event->covers(...)));
    }
}
