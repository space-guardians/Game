<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Prerequisite;
use App\Enum\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Prérequis croisés bâtiments / technologies (contenu de jeu, §4.4, §5.6.1) : création, réglage du niveau,
 * suppression par le game design.
 *
 * @extends AbstractCrudController<Prerequisite>
 */
#[AdminRoute(path: '/prerequis', name: 'prerequisite')]
final class PrerequisiteCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return Prerequisite::class;
    }

    public function createEntity(string $entityFqcn): Prerequisite
    {
        return new Prerequisite();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Prérequis')
            ->setEntityLabelInPlural('Prérequis')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Prerequisite $prerequisite): string => (string) $prerequisite)
            ->setDefaultSort(['id' => 'ASC'])
            ->setPaginatorPageSize(100);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::DELETE] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'prerequisite');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('targetBuilding', 'Bâtiment cible'))
            ->add(EntityFilter::new('targetTechnology', 'Technologie cible'))
            ->add(EntityFilter::new('requiredBuilding', 'Bâtiment requis'))
            ->add(EntityFilter::new('requiredTechnology', 'Technologie requise'));
    }

    public function configureFields(string $pageName): iterable
    {
        // Liste et fiche : une colonne par rôle, quelle que soit la forme (bâtiment ou technologie)
        yield TextField::new('target', 'Cible')->hideOnForm()->setSortable(false);
        yield TextField::new('required', 'Requis')->hideOnForm()->setSortable(false);

        yield FormField::addFieldset('Cible')->setHelp('Ce qui est verrouillé : un bâtiment ou une technologie.');
        yield AssociationField::new('targetBuilding', 'Bâtiment')->onlyOnForms()->setRequired(false);
        yield AssociationField::new('targetTechnology', 'Technologie')->onlyOnForms()->setRequired(false);

        yield FormField::addFieldset('Requis')->setHelp('Un bâtiment (sur la planète concernée) ou une technologie (de l’empire).');
        yield AssociationField::new('requiredBuilding', 'Bâtiment')->onlyOnForms()->setRequired(false);
        yield AssociationField::new('requiredTechnology', 'Technologie')->onlyOnForms()->setRequired(false);

        yield IntegerField::new('level', 'Niveau requis');
    }
}
