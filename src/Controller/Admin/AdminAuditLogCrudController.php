<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminRole;
use App\Admin\AuditAction;
use App\Entity\AdminAuditLog;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

/**
 * Journal des actions d'administration (génération, sanction…), en consultation seule (§5.6.2) : aucune action
 * d'écriture, et la table refuse les mises à jour. Les modifications de données sont dans l'historique (§5.6.3).
 *
 * @extends AbstractCrudController<AdminAuditLog>
 */
#[AdminRoute(path: '/journal-actions', name: 'audit_log')]
final class AdminAuditLogCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AdminAuditLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Entrée du journal')
            ->setEntityLabelInPlural('Journal des actions')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(AdminAuditLog $entry): string => (string) $entry)
            ->setDefaultSort(['occurredAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['actorEmail', 'subjectLabel'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::Admin->value)
            ->setPermission(Action::DETAIL, AdminRole::Admin->value);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('occurredAt', 'Date'))
            ->add(TextFilter::new('actorEmail', 'Auteur'))
            ->add(ChoiceFilter::new('action', 'Action')->setChoices(array_combine(
                array_map(static fn(AuditAction $action): string => $action->label(), AuditAction::cases()),
                array_column(AuditAction::cases(), 'value'),
            )))
            ->add(TextFilter::new('subjectType', 'Type d’objet'))
            ->add(TextFilter::new('subjectLabel', 'Objet'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('occurredAt', 'Date')->setFormat('dd/MM/yyyy HH:mm:ss');
        yield TextField::new('actorEmail', 'Auteur');
        yield ChoiceField::new('action', 'Action');
        yield TextField::new('subjectType', 'Type d’objet');
        yield TextField::new('subjectId', 'Identifiant')->onlyOnDetail();
        yield TextField::new('subjectLabel', 'Objet');
        yield ArrayField::new('changes', 'Valeurs avant / après')
            ->onlyOnDetail()
            ->setTemplatePath('admin/audit_log/changes.html.twig');
    }
}
