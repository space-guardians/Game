<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\StarSystem;
use App\Enum\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

/**
 * Systèmes stellaires, en consultation seule : leurs positions sont produites par la génération,
 * qui garantit la distance minimale entre systèmes (§2.2).
 *
 * @extends AbstractCrudController<StarSystem>
 */
#[AdminRoute(path: '/systemes', name: 'star_system')]
final class StarSystemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return StarSystem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Système')
            ->setEntityLabelInPlural('Systèmes')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(StarSystem $system): string => (string) $system)
            ->setDefaultSort(['galaxy' => 'ASC', 'number' => 'ASC'])
            ->setSearchFields(null)
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::GameDesigner->value)
            ->setPermission(Action::DETAIL, AdminRole::GameDesigner->value);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('galaxy', 'Galaxie'))
            ->add(NumericFilter::new('number', 'Numéro'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('galaxy', 'Galaxie');
        yield IntegerField::new('number', 'Numéro');
        yield NumberField::new('position.x', 'X')->setNumDecimals(1)->setSortable(false);
        yield NumberField::new('position.y', 'Y')->setNumDecimals(1)->setSortable(false);
        yield NumberField::new('distanceFromCenter', 'Distance au centre')->setNumDecimals(0)->setSortable(false);
        yield IntegerField::new('planetCount', 'Planètes')->setSortable(false)->onlyOnIndex();
        yield CollectionField::new('planets', 'Planètes')->onlyOnDetail();
    }
}
