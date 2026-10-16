<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ShipType;
use App\Enum\Admin\AdminRole;
use App\Enum\Fleet\ShipCategory;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Types de défenses planétaires (contenu de jeu, §4.8, §5.6.1) : des types de vaisseaux de catégorie « défense »,
 * construits au chantier spatial mais immobiles. Coût et caractéristiques de combat réglables, nouveaux types ; pas de
 * suppression (un type construit doit rester connu).
 *
 * @extends AbstractCrudController<ShipType>
 */
#[AdminRoute(path: '/defenses', name: 'defense_type')]
final class DefenseTypeCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return ShipType::class;
    }

    public function createEntity(string $entityFqcn): ShipType
    {
        $type = new ShipType(category: ShipCategory::Defense);
        // Immobile : ni vitesse, ni cargo, ni réservoir
        $type->setSpeed(0);

        return $type;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.category = :defense')
            ->setParameter('defense', ShipCategory::Defense);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Type de défense')
            ->setEntityLabelInPlural('Défenses')
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

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Identité');
        yield TextField::new('code', 'Code')
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName)
            ->setHelp('Identifiant stable : minuscules, chiffres et « _ ».')
            ->hideOnIndex();
        yield TextField::new('name', 'Nom');
        yield AssociationField::new('shipClass', 'Classe')
            ->setRequired(false)
            ->setHelp('Facultative : sans classe, la défense est neutre dans la matrice des classes.');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Ordre d’affichage')->hideOnIndex();

        yield FormField::addFieldset('Coût d’une unité');
        yield NumberField::new('costMetal', 'Métal')->setNumDecimals(0)->hideOnIndex();
        yield NumberField::new('costCrystal', 'Cristal')->setNumDecimals(0)->hideOnIndex();
        yield NumberField::new('costDeuterium', 'Deutérium')->setNumDecimals(0)->hideOnIndex();
        yield NumberField::new('costAntimatter', 'Antimatière')->setNumDecimals(0)->hideOnIndex()
            ->setHelp('Ressource rare, gagnée seulement sur les civilisations « boss » (§4.14).');

        yield FormField::addFieldset('Combat');
        yield IntegerField::new('attack', 'Attaque');
        yield IntegerField::new('shield', 'Bouclier');
        yield IntegerField::new('structure', 'Structure');
    }
}
