<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Alliance;
use App\Enum\Admin\AdminRole;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Alliances (§4.10), en lecture seule pour la modération : nom, tag, description, membres. Elles se gèrent en jeu par
 * leurs fondateurs.
 *
 * @extends AbstractCrudController<Alliance>
 */
#[AdminRoute(path: '/alliances', name: 'alliance')]
final class AllianceCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public static function getEntityFqcn(): string
    {
        return Alliance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Alliance')
            ->setEntityLabelInPlural('Alliances')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Alliance $alliance): string => (string) $alliance)
            ->setDefaultSort(['name' => 'ASC'])
            ->setSearchFields(['name', 'tag']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL] as $action) {
            $actions->setPermission($action, AdminRole::Moderator->value);
        }

        return $this->addHistoryAction($actions, 'alliance');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('tag', 'Tag');
        yield TextField::new('name', 'Nom');
        yield DateTimeField::new('foundedAt', 'Fondée le');
        yield AssociationField::new('members', 'Membres')->setTemplatePath('admin/alliance/members.html.twig');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
    }
}
