<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Empire;
use App\Enum\Admin\AdminRole;
use App\Service\Admin\EntityHistory;
use App\Service\Admin\PlayerFiles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Joueurs (§5.6.1) : liste et recherche (empire, adresse e-mail), fiche empire avec planètes, ressources,
 * bâtiments et journal d'activité. Un joueur est identifié par son empire, créé avec son compte à l'inscription.
 * Consultation seule : les actions de modération arrivent avec le back-office de modération (#78).
 *
 * @extends AbstractCrudController<Empire>
 */
#[AdminRoute(path: '/joueurs', name: 'player')]
final class PlayerCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    public function __construct(
        private readonly PlayerFiles $files,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Empire::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Joueur')
            ->setEntityLabelInPlural('Joueurs')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(Empire $empire): string => \sprintf('%s — %s', $empire->getName(), $empire->getUser()->getEmail()))
            ->setDefaultSort(['foundedAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['name', 'user.email'])
            ->setPaginatorPageSize(50)
            ->overrideTemplate('crud/detail', 'admin/player/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::INDEX, AdminRole::Moderator->value)
            ->setPermission(Action::DETAIL, AdminRole::Moderator->value);

        return $this->addHistoryAction($actions, 'empire');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('foundedAt', 'Inscription'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Empire');
        yield TextField::new('user.email', 'Adresse e-mail');
        yield IntegerField::new('score', 'Score');
        yield ChoiceField::new('orientation', 'Orientation')->hideOnIndex();
        yield DateTimeField::new('foundedAt', 'Inscription')->setFormat('dd/MM/yyyy HH:mm');
        yield DateTimeField::new('user.lastActiveAt', 'Dernière activité')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->formatValue(static fn(mixed $value): string => $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : 'Jamais');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $empire = $responseParameters->get('entity')->getInstance();
            \assert($empire instanceof Empire);
            $responseParameters->set('player', $this->files->of($empire));
            $responseParameters->set('history_types', EntityHistory::TYPE_LABELS);
        }

        return $responseParameters;
    }
}
