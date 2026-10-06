<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ShipType;
use App\Enum\Admin\AdminRole;
use App\Enum\Fleet\ShipCategory;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Types de vaisseaux (contenu de jeu, §4.5, §5.6.1) : coût et caractéristiques réglables, classe de combat (obligatoire
 * pour un militaire), nouveaux types. Pas de suppression : certains types ont un rôle propre dans le code (colonisateur,
 * recycleur, sonde), et un type construit doit rester connu.
 *
 * @extends AbstractCrudController<ShipType>
 */
#[AdminRoute(path: '/vaisseaux', name: 'ship_type')]
final class ShipTypeCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return ShipType::class;
    }

    public function createEntity(string $entityFqcn): ShipType
    {
        return new ShipType();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Type de vaisseau')
            ->setEntityLabelInPlural('Vaisseaux')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(ShipType $type): string => (string) $type)
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['name', 'code']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'ship_type');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('category', 'Catégorie')->setChoices(array_combine(
                array_map(static fn(ShipCategory $category): string => $category->label(), ShipCategory::cases()),
                array_column(ShipCategory::cases(), 'value'),
            )))
            ->add(EntityFilter::new('shipClass', 'Classe'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Identité');
        yield TextField::new('code', 'Code')
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName)
            ->setHelp('Identifiant stable : minuscules, chiffres et « _ ».')
            ->hideOnIndex();
        yield TextField::new('name', 'Nom');
        yield ChoiceField::new('category', 'Catégorie');
        yield AssociationField::new('shipClass', 'Classe')
            ->setRequired(false)
            ->setHelp('Obligatoire pour un vaisseau militaire : elle fixe ses bonus et malus en combat.');
        yield AssociationField::new('drive', 'Propulsion')->setRequired(false)->hideOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Ordre d’affichage')->hideOnIndex();

        yield FormField::addFieldset('Coût d’un vaisseau');
        yield NumberField::new('costMetal', 'Métal')->setNumDecimals(0)->hideOnIndex();
        yield NumberField::new('costCrystal', 'Cristal')->setNumDecimals(0)->hideOnIndex();
        yield NumberField::new('costDeuterium', 'Deutérium')->setNumDecimals(0)->hideOnIndex();

        yield FormField::addFieldset('Combat');
        yield IntegerField::new('attack', 'Attaque');
        yield IntegerField::new('shield', 'Bouclier');
        yield IntegerField::new('structure', 'Structure');

        yield FormField::addFieldset('Déplacement');
        yield IntegerField::new('speed', 'Vitesse');
        yield IntegerField::new('cargo', 'Cargo');
        yield IntegerField::new('fuelConsumption', 'Consommation')->setHelp('Deutérium de base d’un trajet.')->hideOnIndex();
        yield IntegerField::new('fuelCapacity', 'Réservoir')->setHelp('Deutérium emportable au-delà du trajet prévu.')->hideOnIndex();
    }
}
