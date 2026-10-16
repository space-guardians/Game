<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\NpcBehaviorProfile;
use App\Enum\Admin\AdminRole;
use App\Enum\Pve\NpcCategory;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Profils de comportement des civilisations non-joueuses (§4.14, §5.6.1 « IA », rôle GameDesigner) : réglage des raids,
 * du recalcul de flotte et du butin sans redéploiement. Pas de suppression : des civilisations les utilisent.
 *
 * @extends AbstractCrudController<NpcBehaviorProfile>
 */
#[AdminRoute(path: '/profils-npc', name: 'npc_profile')]
final class NpcBehaviorProfileCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return NpcBehaviorProfile::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Profil de civilisation NPC')
            ->setEntityLabelInPlural('Profils des civilisations NPC')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(NpcBehaviorProfile $profile): string => (string) $profile)
            ->setDefaultSort(['category' => 'ASC', 'name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::SAVE_AND_RETURN, Action::SAVE_AND_CONTINUE, Action::SAVE_AND_ADD_ANOTHER] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'npc_behavior_profile');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('code', 'Code');
        yield TextField::new('name', 'Nom');
        yield ChoiceField::new('category', 'Catégorie')->setChoices(array_combine(array_map(static fn(NpcCategory $category): string => $category->label(), NpcCategory::cases()), NpcCategory::cases()));
        yield BooleanField::new('raidsEnabled', 'Raids sur les joueurs proches')->renderAsSwitch(false);
        yield IntegerField::new('raidIntervalHours', 'Intervalle entre raids (h)')->hideOnIndex();
        yield NumberField::new('raidRange', 'Portée des raids')->hideOnIndex();
        yield IntegerField::new('fleetRecalcHours', 'Recalcul de la flotte (h, boss)')->hideOnIndex();
        yield IntegerField::new('fleetStrengthPercent', 'Force de la flotte (% du top 10, boss)')->hideOnIndex();
        yield IntegerField::new('lootPercent', 'Butin (% des ressources pillées)');
        yield TextField::new('fleetCompositionText', 'Flotte (code:quantité, …) — de départ, ou de base d’un boss')->hideOnIndex();
        yield TextField::new('defenseCompositionText', 'Défenses à la fondation (code:quantité, …)')->hideOnIndex();
        yield IntegerField::new('victoryMetal', 'Récompense de victoire : métal')->hideOnIndex();
        yield IntegerField::new('victoryCrystal', 'Récompense de victoire : cristal')->hideOnIndex();
        yield IntegerField::new('victoryDeuterium', 'Récompense de victoire : deutérium')->hideOnIndex();
        yield IntegerField::new('victoryAntimatter', 'Récompense de victoire : antimatière')->hideOnIndex()
            ->setHelp('Ressource rare (§4.14), à réserver aux « boss ».');
        yield AssociationField::new('victoryBlueprint', 'Récompense de victoire : plan de vaisseau')->hideOnIndex()->setRequired(false)
            ->setQueryBuilder(static fn(QueryBuilder $builder): QueryBuilder => $builder->andWhere('entity.requiresBlueprint = true'))
            ->setHelp('Type « construit sur plan » que l’empire vainqueur peut ensuite construire ; remporté une seule fois.');
    }
}
