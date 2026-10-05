<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Galaxy;
use App\Enum\Admin\AdminRole;
use App\Repository\PlanetRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
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
    use HistoryActionTrait;

    public function __construct(
        private readonly PlanetRepository $planets,
    ) {}

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

    /** Une galaxie habitée ne se supprime pas : ses empires perdraient leur planète mère */
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $owned = $this->planets->countOwnedIn($entityInstance);
        if ($owned > 0) {
            $this->addFlash('danger', \sprintf('%s n’a pas été supprimée : %d planète%s appartien%s à des empires.', $entityInstance, $owned, $owned > 1 ? 's' : '', $owned > 1 ? 'nent' : 't'));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $this->addHistoryAction($actions, 'galaxy')
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::GameDesigner->value)
            ->setPermission(Action::DETAIL, AdminRole::GameDesigner->value)
            ->setPermission(Action::EDIT, AdminRole::Admin->value)
            ->setPermission(Action::DELETE, AdminRole::Admin->value)
            ->setPermission(Action::BATCH_DELETE, AdminRole::Admin->value);
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
