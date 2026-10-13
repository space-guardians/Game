<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Merchant;
use App\Enum\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

/**
 * Marchands PNJ (§4.15, §5.6.1 « Univers ») : placés par la génération de galaxie (ou `app:merchant:place`) à des
 * positions fixes ; seul leur nom se modifie. Ni création ni suppression : ils gardent les ressources des joueurs.
 *
 * @extends AbstractCrudController<Merchant>
 */
#[AdminRoute(path: '/marchands', name: 'merchant')]
final class MerchantCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return Merchant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Marchand')
            ->setEntityLabelInPlural('Marchands')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Merchant $merchant): string => (string) $merchant)
            ->setDefaultSort(['system' => 'ASC'])
            ->setSearchFields(['name'])
            ->setPaginatorPageSize(50);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::GameDesigner->value)
            ->setPermission(Action::DETAIL, AdminRole::GameDesigner->value)
            ->setPermission(Action::EDIT, AdminRole::GameDesigner->value)
            ->setPermission(Action::SAVE_AND_RETURN, AdminRole::GameDesigner->value)
            ->setPermission(Action::SAVE_AND_CONTINUE, AdminRole::GameDesigner->value);

        return $this->addHistoryAction($actions, 'merchant');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(EntityFilter::new('system', 'Système'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom')->setFormTypeOption('attr', ['maxlength' => Merchant::NAME_MAX_LENGTH]);
        yield AssociationField::new('system', 'Système')->setFormTypeOption('disabled', true);
        yield NumberField::new('localX', 'Station X')->setNumDecimals(1)->hideOnForm();
        yield NumberField::new('localY', 'Station Y')->setNumDecimals(1)->hideOnForm();
    }
}
