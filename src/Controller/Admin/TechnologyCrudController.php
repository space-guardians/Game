<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Technology;
use App\Enum\Admin\AdminRole;
use App\Repository\PrerequisiteRepository;
use App\Service\Research\ResearchRules;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Technologies de l'arbre de recherche (contenu de jeu, §4.4, §5.6.1) : coûts réglables par le game design, avec
 * leurs prérequis sur la fiche. Ni création ni suppression : chaque technologie est référencée par son code.
 *
 * @extends AbstractCrudController<Technology>
 */
#[AdminRoute(path: '/technologies', name: 'technology')]
final class TechnologyCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    /** Niveaux montrés dans l'aperçu de la fiche */
    private const int PREVIEW_LEVELS = 10;

    public function __construct(
        private readonly ResearchRules $rules,
        private readonly PrerequisiteRepository $prerequisites,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Technology::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Technologie')
            ->setEntityLabelInPlural('Technologies')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Technology $technology): string => (string) $technology)
            ->setDefaultSort(['sortOrder' => 'ASC', 'id' => 'ASC'])
            ->setSearchFields(['name', 'code'])
            ->overrideTemplate('crud/detail', 'admin/technology/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::EDIT] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'technology');
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Identité');
        yield TextField::new('code', 'Code')->setFormTypeOption('disabled', true)->hideOnIndex();
        yield TextField::new('name', 'Nom');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield IntegerField::new('sortOrder', 'Ordre d’affichage')->hideOnIndex();

        yield FormField::addFieldset('Coût du niveau 1')->setHelp('Le niveau n coûte ce montant × facteur^(n − 1).');
        yield NumberField::new('baseCostMetal', 'Métal')->setNumDecimals(0);
        yield NumberField::new('baseCostCrystal', 'Cristal')->setNumDecimals(0);
        yield NumberField::new('baseCostDeuterium', 'Deutérium')->setNumDecimals(0);
        yield NumberField::new('costFactor', 'Facteur de coût')->setNumDecimals(2);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $technology = $responseParameters->get('entity')->getInstance();
            \assert($technology instanceof Technology);
            $levels = [];
            for ($level = 1; $level <= self::PREVIEW_LEVELS; ++$level) {
                $levels[] = ['level' => $level, 'cost' => $this->rules->cost($technology, $level)];
            }
            $responseParameters->set('preview_levels', $levels);
            $responseParameters->set('prerequisites', $this->prerequisites->findFor($technology));
        }

        return $responseParameters;
    }
}
