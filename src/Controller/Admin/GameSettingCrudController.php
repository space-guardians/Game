<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\GameSetting;
use App\Enum\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Paramètres globaux du jeu (§5.6.1 « Contenu de jeu », rôle GameDesigner) : vitesse d'univers… Seule la valeur se
 * modifie ; le worker la prend en compte à son redémarrage.
 *
 * @extends AbstractCrudController<GameSetting>
 */
#[AdminRoute(path: '/parametres', name: 'game_setting')]
final class GameSettingCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return GameSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Paramètre du jeu')
            ->setEntityLabelInPlural('Paramètres du jeu')
            ->setHelp(Crud::PAGE_INDEX, 'Pris en compte aussitôt par le site, et par le worker (constructions, recherches, trajets) à son prochain redémarrage. Les durées déjà lancées ne changent pas.');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL);
        foreach ([Action::INDEX, Action::EDIT, Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $actions;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('label', 'Paramètre')->setFormTypeOption('disabled', true);
        yield TextField::new('code', 'Code')->setFormTypeOption('disabled', true)->hideOnForm();
        yield NumberField::new('value', 'Valeur')->setNumDecimals(2);
    }
}
