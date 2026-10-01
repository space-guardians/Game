<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Galaxy;
use Doctrine\Common\Collections\Collection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Galaxies : consultation (game design) ; renommage et suppression (administration).
 * La création passe par la génération d'une galaxie (#106), jamais par un formulaire libre.
 *
 * @extends AbstractCrudController<Galaxy>
 */
#[AdminRoute(path: '/galaxies', name: 'galaxy')]
final class GalaxyCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Galaxy::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Galaxie')
            ->setEntityLabelInPlural('Galaxies')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Galaxy $galaxy): string => (string) $galaxy)
            ->setDefaultSort(['number' => 'ASC'])
            ->setSearchFields(['name']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, 'ROLE_GAME_DESIGNER')
            ->setPermission(Action::DETAIL, 'ROLE_GAME_DESIGNER')
            ->setPermission(Action::EDIT, 'ROLE_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_ADMIN')
            ->setPermission(Action::BATCH_DELETE, 'ROLE_ADMIN');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('number', 'Numéro')->setFormTypeOption('disabled', true);
        yield TextField::new('name', 'Nom');
        yield IntegerField::new('systems', 'Systèmes')
            ->hideOnForm()
            ->setSortable(false)
            // Compté en base sans charger les systèmes (collection EXTRA_LAZY)
            ->formatValue(static fn(?Collection $systems): int => $systems?->count() ?? 0);
    }
}
