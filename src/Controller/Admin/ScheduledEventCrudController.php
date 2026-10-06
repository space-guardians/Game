<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ScheduledEvent;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Enum\Scheduling\ScheduledEventStatus;
use App\Service\Admin\AdminAudit;
use App\Service\Admin\AdminIndicators;
use App\Service\Scheduling\EventScheduler;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CodeEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Supervision des événements planifiés (§5.6.1, section Exploitation) : consultation, et relance des événements
 * en échec (une fois la cause corrigée) ou en retard (réveil perdu, worker arrêté). Les événements ne se créent,
 * ne se modifient ni ne se suppriment ici : c'est le jeu qui les planifie.
 *
 * @extends AbstractCrudController<ScheduledEvent>
 */
#[AdminRoute(path: '/evenements-planifies', name: 'scheduled_event')]
final class ScheduledEventCrudController extends AbstractCrudController
{
    use SameOriginTrait;

    public function __construct(
        private readonly EventScheduler $scheduler,
        private readonly AdminAudit $audit,
        private readonly ClockInterface $clock,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ScheduledEvent::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Événement planifié')
            ->setEntityLabelInPlural('Événements planifiés')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(ScheduledEvent $event): string => (string) $event)
            ->setDefaultSort(['dueAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['type', 'error'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $retry = Action::new('retry', 'Relancer')
            ->linkToCrudAction('retry')
            ->renderAsForm()
            ->askConfirmation('L’événement sera de nouveau résolu par le worker. Continuer ?')
            ->displayIf(fn(ScheduledEvent $event): bool => $this->isRetryable($event));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $retry)
            ->add(Crud::PAGE_DETAIL, $retry)
            ->setPermission(Action::INDEX, AdminRole::Admin->value)
            ->setPermission(Action::DETAIL, AdminRole::Admin->value)
            ->setPermission('retry', AdminRole::Admin->value);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
                array_map(static fn(ScheduledEventStatus $status): string => $status->label(), ScheduledEventStatus::cases()),
                array_column(ScheduledEventStatus::cases(), 'value'),
            )))
            ->add(TextFilter::new('type', 'Type'))
            ->add(DateTimeFilter::new('dueAt', 'Échéance'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'N°');
        yield TextField::new('type', 'Type')->formatValue(
            static fn(string $type): string => isset(AdminIndicators::EVENT_LABELS[$type]) ? \sprintf('%s (%s)', AdminIndicators::EVENT_LABELS[$type], $type) : $type,
        );
        yield AssociationField::new('planet', 'Planète');
        yield DateTimeField::new('dueAt', 'Échéance')->setFormat('dd/MM/yyyy HH:mm:ss');
        // EasyAdmin passe le nom du cas (« Failed ») pour un enum traduisible, pas sa valeur
        yield ChoiceField::new('status', 'Statut')->renderAsBadges(
            static fn(mixed $status): string => (array_find(
                ScheduledEventStatus::cases(),
                static fn(ScheduledEventStatus $case): bool => $case === $status || $case->name === $status || $case->value === $status,
            ) ?? ScheduledEventStatus::Pending)->badge(),
        );
        yield TextField::new('lateness', 'Retard')
            ->setVirtual(true)
            ->formatValue(fn(mixed $value, ScheduledEvent $event): string => $this->isLate($event) ? 'En retard' : '—');
        yield IntegerField::new('attempts', 'Tentatives');
        yield DateTimeField::new('resolvedAt', 'Résolu le')->setFormat('dd/MM/yyyy HH:mm:ss');
        yield DateTimeField::new('createdAt', 'Planifié le')->setFormat('dd/MM/yyyy HH:mm:ss')->onlyOnDetail();
        yield TextareaField::new('error', 'Erreur')->onlyOnDetail();
        yield CodeEditorField::new('payload', 'Données')
            ->onlyOnDetail()
            ->formatValue(static fn(mixed $payload): string => json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    /**
     * Relance un événement en échec ou en retard, en POST depuis le panneau, et l'inscrit au journal (§5.6.2).
     *
     * @param AdminContext<ScheduledEvent> $context
     */
    #[AdminRoute(path: '/{entityId}/relancer', name: 'retry', options: ['methods' => ['POST']])]
    public function retry(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::Admin->value);
        $this->denyUnlessSameOrigin($request);
        $event = $context->getEntity()->getInstance();
        \assert($event instanceof ScheduledEvent);

        if ($this->isRetryable($event)) {
            // Un événement en retard est seulement réveillé ; un événement en échec repasse en attente
            $changes = ScheduledEventStatus::Failed === $event->getStatus()
                ? ['status' => [ScheduledEventStatus::Failed, ScheduledEventStatus::Pending], 'error' => [$event->getError(), null]]
                : [];
            $this->scheduler->retry($event);
            $this->audit->record(AuditAction::Retry, $event, $changes);
            $this->addFlash('success', \sprintf('Événement %s relancé : le worker va le résoudre.', $event));
        } else {
            $this->addFlash('warning', \sprintf('L’événement %s n’est ni en échec ni en retard : rien à relancer.', $event));
        }

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($event->getId())->generateUrl());
    }

    private function isRetryable(ScheduledEvent $event): bool
    {
        return ScheduledEventStatus::Failed === $event->getStatus() || $this->isLate($event);
    }

    private function isLate(ScheduledEvent $event): bool
    {
        return $event->isLate($this->clock->now(), AdminIndicators::LATE_AFTER);
    }
}
