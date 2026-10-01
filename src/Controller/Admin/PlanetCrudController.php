<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Planet;
use App\Entity\PlanetAddress;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;

/**
 * Planètes, en consultation seule : orbite, rayon et température sont fixés à la génération (§2.2).
 *
 * @extends AbstractCrudController<Planet>
 */
#[AdminRoute(path: '/planetes', name: 'planet')]
final class PlanetCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Planet::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Planète')
            ->setEntityLabelInPlural('Planètes')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Planet $planet): string => 'Planète ' . $planet)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(null)
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, 'ROLE_GAME_DESIGNER')
            ->setPermission(Action::DETAIL, 'ROLE_GAME_DESIGNER');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(NumericFilter::new('position.orbit', 'Orbite'))
            ->add(NumericFilter::new('temperature', 'Température (°C)'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('address', 'Adresse')
            ->setSortable(false)
            // Adresse entre crochets, comme partout dans l'interface (charte §3)
            ->formatValue(static fn(?PlanetAddress $address): string => '[' . $address . ']');
        yield AssociationField::new('system', 'Système');
        yield IntegerField::new('position.orbit', 'Orbite')->setSortable(false);
        yield NumberField::new('position.radius', 'Rayon')->setNumDecimals(1)->setSortable(false);
        yield NumberField::new('position.angle', 'Angle')
            ->setSortable(false)
            ->formatValue(static fn(?float $radians): string => number_format(rad2deg((float) $radians), 0) . '°');
        yield IntegerField::new('temperature', 'Température (°C)');
    }
}
