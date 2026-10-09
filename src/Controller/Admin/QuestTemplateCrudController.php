<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Enum\Admin\AdminRole;
use App\Enum\Exploration\QuestResolution;
use App\Enum\Exploration\QuestStatus;
use App\Form\Admin\QuestOutcomeType;
use App\Model\Economy\Resources;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Exploration\QuestChains;
use App\Service\Exploration\QuestPreview;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

/**
 * Éditeur de quêtes et d'événements d'exploration (contenu de jeu, §4.6.4, §5.6.1) : texte, probabilité
 * d'apparition, conditions de déclenchement, issues avec leurs récompenses et risques, chaînage. Une quête naît
 * brouillon : sa fiche montre son arbre de chaînage et la teste sur une flotte de test avant publication. Supprimer
 * un gabarit laisse les événements déjà vécus dans le journal des joueurs (titre et textes recopiés).
 *
 * @extends AbstractCrudController<QuestTemplate>
 */
#[AdminRoute(path: '/quetes', name: 'quest_template')]
final class QuestTemplateCrudController extends AbstractCrudController
{
    use HistoryActionTrait;

    /** Vaisseau de la flotte de test quand la quête n'en exige aucun */
    private const string DEFAULT_TEST_SHIP = 'small_cargo';

    public function __construct(
        private readonly QuestPreview $preview,
        private readonly QuestChains $chains,
        private readonly TechnologyRepository $technologies,
        private readonly ShipTypeRepository $shipTypes,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public static function getEntityFqcn(): string
    {
        return QuestTemplate::class;
    }

    public function createEntity(string $entityFqcn): QuestTemplate
    {
        return new QuestTemplate();
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Quête')
            ->setEntityLabelInPlural('Quêtes d’exploration')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn(QuestTemplate $template): string => (string) $template)
            ->setDefaultSort(['id' => 'ASC'])
            ->setSearchFields(['name', 'code', 'text'])
            ->overrideTemplate('crud/detail', 'admin/quest_template/detail.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::DELETE] as $action) {
            $actions->setPermission($action, AdminRole::GameDesigner->value);
        }

        return $this->addHistoryAction($actions, 'quest_template');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_combine(
                array_map(static fn(QuestStatus $status): string => $status->label(), QuestStatus::cases()),
                QuestStatus::cases(),
            )))
            ->add(ChoiceFilter::new('resolution', 'Résolution')->setChoices(array_combine(
                array_map(static fn(QuestResolution $resolution): string => $resolution->label(), QuestResolution::cases()),
                QuestResolution::cases(),
            )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Quête');
        yield TextField::new('code', 'Code')
            ->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName)
            ->setHelp('Identifiant stable : minuscules, chiffres et « _ ».')
            ->hideOnIndex();
        yield TextField::new('name', 'Titre');
        yield TextareaField::new('text', 'Texte')->hideOnIndex()->setHelp('Récit présenté au joueur à l’apparition.');
        yield ChoiceField::new('status', 'Statut')
            ->setChoices(QuestStatus::cases())
            ->renderAsBadges(array_combine(
                array_map(static fn(QuestStatus $status): string => $status->value, QuestStatus::cases()),
                array_map(static fn(QuestStatus $status): string => $status->badge(), QuestStatus::cases()),
            ))
            ->setHelp('Brouillon : se prépare et se teste sans apparaître en jeu. Publiée : apparaît en exploration et s’enchaîne. Archivée : retirée du jeu.');
        yield ChoiceField::new('resolution', 'Résolution')
            ->setChoices(QuestResolution::cases())
            ->setHelp('Automatique : une issue est tirée au sort selon les poids. Choix du joueur : les issues sont proposées.');
        yield IntegerField::new('chance', 'Apparition (%)')
            ->setHelp('Probabilité d’apparaître à l’arrivée d’une exploration. 0 : seulement en suite d’une autre quête.');
        yield IntegerField::new('responseMinutes', 'Délai de réponse (min)')
            ->hideOnIndex()
            ->setHelp('Quête à choix : passé ce délai, l’occasion est perdue et la flotte reprend ses ordres.');

        yield FormField::addFieldset('Conditions de déclenchement')->setHelp('Toutes doivent être remplies par la flotte et son empire ; laissez vide pour ne rien exiger.');
        yield AssociationField::new('requiredTechnology', 'Technologie de l’empire')->hideOnIndex()->setRequired(false);
        yield IntegerField::new('requiredTechnologyLevel', 'Niveau minimal')->hideOnIndex();
        yield AssociationField::new('requiredShipType', 'Vaisseau dans la flotte')->hideOnIndex()->setRequired(false);
        yield IntegerField::new('requiredShipCount', 'Nombre minimal')->hideOnIndex();
        yield IntegerField::new('requiredCargoMetal', 'Métal en cargaison')->hideOnIndex();
        yield IntegerField::new('requiredCargoCrystal', 'Cristal en cargaison')->hideOnIndex();
        yield IntegerField::new('requiredCargoDeuterium', 'Deutérium en cargaison')->hideOnIndex();

        yield FormField::addFieldset('Issues')->setHelp('Récompenses et risques de chaque issue ; une issue peut enchaîner une quête suivante.');
        yield CollectionField::new('outcomes', 'Issues')
            ->setEntryType(QuestOutcomeType::class)
            ->setFormTypeOption('by_reference', false)
            ->allowAdd()
            ->allowDelete()
            ->renderExpanded()
            ->hideOnIndex()
            ->setTemplatePath('admin/quest_template/outcomes.html.twig');
    }

    /**
     * Fiche : arbre de chaînage, quêtes qui y mènent, et test sur une flotte de test (paramètres « test » de l'URL,
     * flotte minimale par défaut). Simple lecture : rien n'est enregistré.
     */
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_DETAIL !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }
        $template = $responseParameters->get('entity')->getInstance();
        \assert($template instanceof QuestTemplate);

        $test = $this->getContext()?->getRequest()->query->all('test') ?? [];
        $fleet = [] === $test ? $this->preview->minimalFleet($template, self::DEFAULT_TEST_SHIP) : [
            'technologies' => $this->counts($test['technologies'] ?? []),
            'ships' => $this->counts($test['ships'] ?? []),
            'cargo' => $this->cargo($test['cargo'] ?? []),
        ];
        $shipTypes = $this->shipTypes->findAllOrdered();
        $cargoPerShip = [];
        foreach ($shipTypes as $type) {
            $cargoPerShip[$type->getCode()] = $type->getCargo();
        }

        $responseParameters->set('chain', $this->chains->tree($template));
        $responseParameters->set('predecessors', $this->entityManager->getRepository(QuestOutcome::class)->findBy(['nextQuest' => $template], ['id' => 'ASC']));
        $responseParameters->set('test_fleet', $fleet);
        $responseParameters->set('test_technologies', $this->technologies->findAllOrdered());
        $responseParameters->set('test_ship_types', $shipTypes);
        $responseParameters->set('simulation', $this->preview->simulate($template, $fleet['technologies'], $fleet['ships'], $fleet['cargo'], $cargoPerShip));

        return $responseParameters;
    }

    /**
     * Nombres saisis par code (niveaux, vaisseaux), sans les zéros.
     *
     * @return array<string, int>
     */
    private function counts(mixed $input): array
    {
        $counts = [];
        foreach (\is_array($input) ? $input : [] as $code => $value) {
            $value = max(0, (int) $value);
            if ($value > 0) {
                $counts[(string) $code] = $value;
            }
        }

        return $counts;
    }

    private function cargo(mixed $input): Resources
    {
        $input = \is_array($input) ? $input : [];
        $amount = static fn(string $key): float => max(0.0, (float) ($input[$key] ?? 0));

        return new Resources($amount('metal'), $amount('crystal'), $amount('deuterium'));
    }
}
