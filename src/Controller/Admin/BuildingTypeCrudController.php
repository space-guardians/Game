<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BuildingType;
use App\Enum\Admin\AdminRole;
use App\Service\Economy\BuildingRules;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Types de bâtiments (contenu de jeu, §4.3, §5.6.1) : coûts et paramètres des effets, réglables par le game design.
 * Ni création ni suppression : chaque type est lié à une formule du code (effet) et référencé par son code.
 *
 * @extends AbstractCrudController<BuildingType>
 */
#[AdminRoute(path: '/batiments', name: 'building_type')]
final class BuildingTypeCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    /** Niveaux montrés dans l'aperçu de la fiche */
    private const int PREVIEW_LEVELS = 10;

    /** Température de référence de l'aperçu (°C) */
    private const int PREVIEW_TEMPERATURE = 20;

    public function __construct(
        private readonly BuildingRules $rules,
    ) {}

    public static function getEntityFqcn(): string
    {
        return BuildingType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Type de bâtiment')
            ->setEntityLabelInPlural('Bâtiments')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(BuildingType $type): string => (string) $type)
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['name', 'code'])
            ->overrideTemplate('crud/detail', 'admin/building_type/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::EDIT] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'building_type');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Identité');
        yield TextField::new('code', 'Code')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield TextField::new('name', 'Nom');
        yield ChoiceField::new('effect', 'Effet')->setFormTypeOption('disabled', true);
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Ordre d’affichage')->hideOnIndex();

        yield FormField::addFieldset('Coût du niveau 1')->setHelp('Le niveau n coûte ce montant × facteur^(n − 1).');
        yield NumberField::new('baseCostMetal', 'Métal')->setNumDecimals(0);
        yield NumberField::new('baseCostCrystal', 'Cristal')->setNumDecimals(0);
        yield NumberField::new('baseCostDeuterium', 'Deutérium')->setNumDecimals(0);
        yield NumberField::new('costFactor', 'Facteur de coût')->setNumDecimals(2);

        yield FormField::addFieldset('Effet')
            ->setHelp('Production ou énergie : base × niveau × croissance^niveau × (base température + coefficient × T). Stockage : base × ⌊2,5 × e^(20 × niveau / 33)⌋.');
        yield NumberField::new('effectBase', 'Base de l’effet')->setNumDecimals(2)->hideOnIndex();
        yield NumberField::new('effectGrowth', 'Croissance par niveau')->setNumDecimals(3)->hideOnIndex();
        yield NumberField::new('energyConsumption', 'Consommation d’énergie')->setNumDecimals(2)->hideOnIndex()
            ->setHelp('Base × niveau × 1,1^niveau.');
        yield NumberField::new('deuteriumConsumption', 'Consommation de deutérium (/h)')->setNumDecimals(2)->hideOnIndex()
            ->setHelp('Base × niveau × 1,1^niveau (centrale à fusion).');
        yield NumberField::new('temperatureBase', 'Base température')->setNumDecimals(3)->hideOnIndex();
        yield NumberField::new('temperatureCoefficient', 'Coefficient de température (par °C)')->setNumDecimals(4)->hideOnIndex();
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $type = $responseParameters->get('entity')->getInstance();
            \assert($type instanceof BuildingType);
            $levels = [];
            for ($level = 1; $level <= self::PREVIEW_LEVELS; ++$level) {
                $levels[] = [
                    'level' => $level,
                    'cost' => $this->rules->cost($type, $level),
                    'output' => $type->getEffect()->isStorage() ? $this->rules->capacity($type, $level) : $this->rules->output($type, $level, self::PREVIEW_TEMPERATURE),
                    'energy' => $this->rules->energyConsumption($type, $level),
                    'deuterium' => $this->rules->deuteriumConsumption($type, $level),
                ];
            }
            $responseParameters->set('preview_levels', $levels);
            $responseParameters->set('preview_temperature', self::PREVIEW_TEMPERATURE);
        }

        return $responseParameters;
    }
}
