<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ShipClass;
use App\Enum\Admin\AdminRole;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Classes de combat des vaisseaux (contenu de jeu, §4.5, §5.6.1) : création, réglage et suppression par le game
 * design. Une classe encore portée par un type de vaisseau ne se supprime pas.
 *
 * @extends AbstractCrudController<ShipClass>
 */
#[AdminRoute(path: '/classes-vaisseaux', name: 'ship_class')]
final class ShipClassCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public function __construct(
        private readonly ShipTypeRepository $shipTypes,
    ) {}

    public static function getEntityFqcn(): string
    {
        return ShipClass::class;
    }

    public function createEntity(string $entityFqcn): ShipClass
    {
        return new ShipClass();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Classe de vaisseau')
            ->setEntityLabelInPlural('Classes de vaisseaux')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(ShipClass $class): string => (string) $class)
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['name', 'code']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::DELETE] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'ship_class');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code')
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName)
            ->setHelp('Identifiant stable : minuscules, chiffres et « _ ».');
        yield TextField::new('name', 'Nom');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Ordre d’affichage')->hideOnIndex();
    }

    /** Une classe portée par un type de vaisseau reste (sa clé étrangère l'interdirait de toute façon) */
    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $users = $this->shipTypes->count(['shipClass' => $entityInstance]);
        if ($users > 0) {
            $this->addFlash('danger', \sprintf('La classe « %s » est encore portée par %d type(s) de vaisseau : changez-leur de classe avant de la supprimer.', $entityInstance, $users));

            return;
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }
}
