<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminRole;
use App\Entity\GalaxyShapeTemplate;
use App\Universe\Generation\GalaxyPreview;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Gabarits de forme de galaxie (contenu de jeu) : la fiche montre un aperçu produit par l'algorithme réel.
 *
 * @extends AbstractCrudController<GalaxyShapeTemplate>
 */
#[AdminRoute(path: '/gabarits-de-forme', name: 'galaxy_shape_template')]
final class GalaxyShapeTemplateCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public function __construct(
        private readonly GalaxyPreview $galaxyPreview,
    ) {}

    public static function getEntityFqcn(): string
    {
        return GalaxyShapeTemplate::class;
    }

    public function createEntity(string $entityFqcn): GalaxyShapeTemplate
    {
        return new GalaxyShapeTemplate('');
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Gabarit de forme')
            ->setEntityLabelInPlural('Gabarits de forme')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(GalaxyShapeTemplate $template): string => 'Gabarit « ' . $template . ' »')
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name'])
            ->overrideTemplate('crud/detail', 'admin/galaxy_shape_template/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'galaxy_shape_template');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield IntegerField::new('arms', 'Branches')->setHelp('Entre 1 et 12.');
        yield NumberField::new('armTightness', 'Enroulement')
            ->setNumDecimals(2)
            ->setHelp('Rotation des branches en s’éloignant du centre, de 0 (droites) à 10 (très enroulées).');
        yield NumberField::new('armWidth', 'Largeur des branches (rad)')
            ->setNumDecimals(2)
            ->setHelp('Entre 0,05 (fines) et 1,5 (diffuses).');
        yield NumberField::new('coreRadius', 'Rayon du bulbe')
            ->setNumDecimals(0)
            ->setHelp('Zone centrale très dense, entre 100 et 20 000.');
        yield NumberField::new('diskScale', 'Échelle du disque')
            ->setNumDecimals(0)
            ->setHelp('Distance de décroissance de la densité, entre 500 et 50 000.');
        yield NumberField::new('interArmDensity', 'Densité entre les branches')
            ->setNumDecimals(2)
            ->setHelp('De 0 (vide) à 1 (aussi dense que les branches).');
    }

    /** La fiche reçoit l'aperçu de la forme (même algorithme que la génération) */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $entity = $responseParameters->get('entity');
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName') && $entity instanceof EntityDto) {
            $template = $entity->getInstance();
            \assert($template instanceof GalaxyShapeTemplate);
            $responseParameters->set('preview_stars', $this->galaxyPreview->stars($template->toShape()));
        }

        return $responseParameters;
    }
}
