<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Entity\GalaxyGeneration;
use App\Enum\Admin\AdminRole;
use App\Enum\Universe\GenerationStatus;
use App\Message\GenerateGalaxy;
use App\Repository\StarSystemRepository;
use App\Service\Universe\GalaxyPreview;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use Psr\Clock\ClockInterface;
use Random\Randomizer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Génération d'une galaxie depuis le panneau (§5.6.1) : formulaire avec confirmation explicite (§5.6.2),
 * exécution en arrière-plan par le worker, suivi en direct de l'avancement et aperçu du résultat.
 * Les demandes ne se modifient ni ne se suppriment : elles forment l'historique des générations.
 *
 * @extends AbstractCrudController<GalaxyGeneration>
 */
#[AdminRoute(path: '/generations', name: 'galaxy_generation')]
final class GalaxyGenerationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly Randomizer $randomizer,
        private readonly MessageBusInterface $bus,
        private readonly StarSystemRepository $systems,
        private readonly GalaxyPreview $preview,
    ) {}

    public static function getEntityFqcn(): string
    {
        return GalaxyGeneration::class;
    }

    public function createEntity(string $entityFqcn): GalaxyGeneration
    {
        $admin = $this->getUser();

        return new GalaxyGeneration($admin instanceof AdminUser ? $admin : null, $this->clock->now());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Génération de galaxie')
            ->setEntityLabelInPlural('Générations de galaxie')
            ->setPageTitle(Crud::PAGE_NEW, 'Générer une galaxie')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(GalaxyGeneration $generation): string => (string) $generation)
            ->setDefaultSort(['requestedAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['name'])
            ->overrideTemplate('crud/detail', 'admin/galaxy_generation/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn(Action $action): Action => $action->setLabel('Générer une galaxie'))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, static fn(Action $action): Action => $action->setLabel('Lancer la génération'))
            ->setPermission(Action::INDEX, AdminRole::GameDesigner->value)
            ->setPermission(Action::DETAIL, AdminRole::GameDesigner->value)
            // Action sensible (§5.6.2) : elle modifie l'univers en jeu
            ->setPermission(Action::NEW, AdminRole::Admin->value);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
            array_map(static fn(GenerationStatus $status): string => $status->label(), GenerationStatus::cases()),
            array_column(GenerationStatus::cases(), 'value'),
        )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('requestedAt', 'Demandée le')->hideOnForm();
        yield TextField::new('name', 'Nom')
            ->setHelp('Vide : « Galaxie <numéro> ».')
            ->formatValue(static fn(?string $value): string => $value ?? '—');
        yield IntegerField::new('systemCount', 'Systèmes')->setHelp('Entre 10 et 5 000.');
        yield AssociationField::new('shapeTemplate', 'Gabarit de forme')
            ->setRequired(false)
            ->setFormTypeOption('placeholder', 'Forme par défaut (spirale à 4 branches)')
            ->setHelp('La forme est recopiée à la demande : modifier le gabarit ensuite ne change pas cette génération.');
        yield IntegerField::new('seed', 'Graine')
            ->setHelp('Vide : tirée au hasard. Une même graine avec les mêmes paramètres redonne la même galaxie.')
            ->hideOnIndex();
        // EasyAdmin passe le nom du cas (« Completed ») pour un enum traduisible, pas sa valeur
        yield ChoiceField::new('status', 'Statut')->hideOnForm()->renderAsBadges(
            static fn(mixed $status): string => (array_find(
                GenerationStatus::cases(),
                static fn(GenerationStatus $case): bool => $case === $status || $case->name === $status || $case->value === $status,
            ) ?? GenerationStatus::Pending)->badge(),
        );
        yield AssociationField::new('galaxy', 'Galaxie')->hideOnForm();
        yield AssociationField::new('requestedBy', 'Demandée par')->hideOnForm();
        yield Field::new('confirmation', 'Je confirme vouloir ajouter cette galaxie à l’univers en jeu')
            ->setFormType(CheckboxType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'constraints' => [new IsTrue(message: 'Confirmez la génération : elle ajoute une galaxie à l’univers en jeu.')],
            ])
            ->onlyWhenCreating();
    }

    /** Fige la demande (graine, forme) et la confie au worker */
    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $entityInstance->lock($this->randomizer->getInt(1, GalaxyGeneration::MAX_SEED));
        parent::persistEntity($entityManager, $entityInstance);

        $this->bus->dispatch(new GenerateGalaxy((int) $entityInstance->getId()));
        $this->addFlash('success', 'Génération lancée : son avancement s’affiche ci-dessous.');
    }

    /** Après la demande, on suit son avancement sur sa fiche */
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        return $this->redirectToRoute('admin_galaxy_generation_detail', ['entityId' => $context->getEntity()->getPrimaryKeyValue()]);
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL === $responseParameters->get('pageName')) {
            $generation = $responseParameters->get('entity')->getInstance();
            \assert($generation instanceof GalaxyGeneration);
            $responseParameters->set('preview_stars', $this->previewOf($generation));
        }

        return $responseParameters;
    }

    /**
     * Bloc de statut, rechargé par le contrôleur Stimulus « generation-status » tant que la génération n'est pas finie.
     *
     * @param AdminContext<GalaxyGeneration> $context
     */
    #[AdminRoute(path: '/{entityId}/statut', name: 'status', options: ['methods' => ['GET']])]
    public function status(AdminContext $context): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::GameDesigner->value);
        $generation = $context->getEntity()->getInstance();
        \assert($generation instanceof GalaxyGeneration);

        return $this->render('admin/galaxy_generation/_status.html.twig', [
            'generation' => $generation,
            'preview_stars' => $this->previewOf($generation),
        ]);
    }

    /** @return list<array{x: float, y: float, r: float, color: string}> */
    private function previewOf(GalaxyGeneration $generation): array
    {
        $galaxy = $generation->getGalaxy();

        return null === $galaxy ? [] : $this->preview->galaxy($this->systems->positions($galaxy));
    }
}
