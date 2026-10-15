<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\MessageReport;
use App\Enum\Admin\AdminRole;
use App\Enum\Moderation\ReportStatus;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signalements du chat et de la messagerie (§4.13, §5.6.1 « Communication & modération », rôle Moderator) : à traiter
 * d'abord ; la fiche montre le message et son contexte, mène à la fiche du joueur signalé pour sanctionner (#78), et se
 * clôt en « traité » ou « classé sans suite ». Sans dépendance au chat : EasyAdmin instancie ses contrôleurs au chargement
 * des routes, hors de portée de Redis en CI.
 *
 * @extends AbstractCrudController<MessageReport>
 */
#[AdminRoute(path: '/signalements', name: 'message_report')]
final class MessageReportCrudController extends AbstractCrudController
{
    use SameOriginTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    public static function getEntityFqcn(): string
    {
        return MessageReport::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Signalement')
            ->setEntityLabelInPlural('Signalements')
            ->setDefaultSort(['status' => 'DESC', 'createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $open = static fn(MessageReport $report): bool => ReportStatus::Open === $report->getStatus();
        $actioned = Action::new('actioned', 'Marquer traité')->linkToCrudAction('actioned')->renderAsForm()->displayIf($open);
        $dismissed = Action::new('dismissed', 'Classer sans suite')->linkToCrudAction('dismissed')->renderAsForm()->displayIf($open);
        $player = Action::new('player', 'Fiche du joueur signalé')->linkToUrl(fn(MessageReport $report): string => $this->generateUrl('admin_player_detail', ['entityId' => $report->getReported()->getId()]));

        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $player)
            ->add(Crud::PAGE_DETAIL, $actioned)
            ->add(Crud::PAGE_DETAIL, $dismissed);
        foreach ([Action::INDEX, Action::DETAIL, 'player', 'actioned', 'dismissed'] as $action) {
            $actions->setPermission($action, AdminRole::Moderator->value);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', 'Signalé le')->setFormat('dd/MM/yyyy HH:mm');
        yield ChoiceField::new('status', 'État');
        yield TextField::new('source.label', 'Origine');
        yield AssociationField::new('reported', 'Joueur signalé');
        yield AssociationField::new('reporter', 'Signalé par');
        yield TextField::new('reason', 'Motif');
        yield TextareaField::new('body', 'Message')->onlyOnDetail();
        yield TextareaField::new('excerptText', 'Contexte')->onlyOnDetail()->renderAsHtml(false);
        yield TextField::new('handledBy', 'Traité par')->onlyOnDetail();
    }

    /** @param AdminContext<MessageReport> $context */
    #[AdminRoute(path: '/{entityId}/traite', name: 'actioned', options: ['methods' => ['POST']])]
    public function actioned(AdminContext $context, Request $request, AdminUrlGenerator $urls): Response
    {
        return $this->close($context, $request, $urls, ReportStatus::Actioned);
    }

    /** @param AdminContext<MessageReport> $context */
    #[AdminRoute(path: '/{entityId}/classe', name: 'dismissed', options: ['methods' => ['POST']])]
    public function dismissed(AdminContext $context, Request $request, AdminUrlGenerator $urls): Response
    {
        return $this->close($context, $request, $urls, ReportStatus::Dismissed);
    }

    /** @param AdminContext<MessageReport> $context */
    private function close(AdminContext $context, Request $request, AdminUrlGenerator $urls, ReportStatus $status): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::Moderator->value);
        $this->denyUnlessSameOrigin($request);
        $report = $context->getEntity()->getInstance();
        \assert($report instanceof MessageReport);
        $moderator = $this->getUser();
        if (ReportStatus::Open === $report->getStatus()) {
            $report->handle($status, $moderator instanceof AdminUser ? $moderator->getUserIdentifier() : 'inconnu', $this->clock->now());
            $this->entityManager->flush();
            $this->addFlash('success', \sprintf('Signalement : %s.', mb_strtolower($status->label())));
        }

        return $this->redirect($urls->setController(self::class)->setAction(Action::DETAIL)->setEntityId($report->getId())->generateUrl());
    }
}
