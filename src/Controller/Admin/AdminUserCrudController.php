<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Service\Admin\AdminAudit;
use App\Service\Admin\AdminTwoFactor;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Comptes d'administration (super administration uniquement) : création, rôle, réinitialisation de la double
 * authentification, suppression. Personne ne modifie ni ne supprime son propre compte depuis cet écran.
 *
 * @extends AbstractCrudController<AdminUser>
 */
#[AdminRoute(path: '/comptes', name: 'account')]
final class AdminUserCrudController extends AbstractCrudController
{
    use HistoryActionTrait;
    use SameOriginTrait;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AdminTwoFactor $twoFactor,
        private readonly AdminAudit $audit,
        private readonly ClockInterface $clock,
    ) {}

    public static function getEntityFqcn(): string
    {
        return AdminUser::class;
    }

    public function createEntity(string $entityFqcn): AdminUser
    {
        return new AdminUser('', AdminRole::Moderator, $this->clock->now());
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Compte d’administration')
            ->setEntityLabelInPlural('Comptes d’administration')
            ->setDefaultSort(['email' => 'ASC'])
            ->setSearchFields(['email'])
            // Le mot de passe n'est exigé qu'à la création
            ->setFormOptions(['validation_groups' => ['Default', 'creation']], ['validation_groups' => ['Default']]);
    }

    public function configureActions(Actions $actions): Actions
    {
        $notMe = fn(AdminUser $admin): bool => !$this->isCurrentAdmin($admin);

        $resetTwoFactor = Action::new('resetTwoFactor', 'Réinitialiser la double authentification')
            ->linkToCrudAction('resetTwoFactor')
            ->renderAsForm()
            ->askConfirmation('Le compte devra réactiver la double authentification à sa prochaine connexion. Continuer ?')
            ->displayIf(static fn(AdminUser $admin): bool => $admin->isTotpAuthenticationEnabled() || $admin->hasPendingTotpSecret())
            ->displayIf($notMe);

        $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $resetTwoFactor)
            ->disable(Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn(Action $action): Action => $action->displayIf($notMe))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn(Action $action): Action => $action->displayIf($notMe))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn(Action $action): Action => $action->displayIf($notMe))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn(Action $action): Action => $action->displayIf($notMe));

        foreach ([Action::INDEX, Action::DETAIL, Action::NEW, Action::EDIT, Action::DELETE, 'resetTwoFactor'] as $action) {
            $actions->setPermission($action, AdminRole::SuperAdmin->value);
        }

        return $this->addHistoryAction($actions, 'admin_user');
    }

    public function configureFields(string $pageName): iterable
    {
        yield EmailField::new('email', 'Adresse e-mail')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        // Choix déduits de l'enum (mapping Doctrine), libellés via AdminRole::trans()
        yield ChoiceField::new('role', 'Rôle');
        yield TextField::new('plainPassword', 'Mot de passe')
            ->setFormType(PasswordType::class)
            ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
            ->setHelp('12 caractères minimum, difficile à deviner. Le compte activera ensuite sa double authentification.')
            ->onlyWhenCreating();
        yield BooleanField::new('totpAuthenticationEnabled', 'Double authentification')->renderAsSwitch(false)->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créé le')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $entityInstance->setPassword($this->passwordHasher->hashPassword($entityInstance, (string) $entityInstance->getPlainPassword()));
        $entityInstance->setPlainPassword(null);

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->denyOwnAccount($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        $this->denyOwnAccount($entityInstance);

        parent::deleteEntity($entityManager, $entityInstance);
    }

    /**
     * Action en POST (formulaire) et depuis le panneau lui-même : protection contre les requêtes intersites.
     *
     * @param AdminContext<AdminUser> $context
     */
    #[AdminRoute(path: '/{entityId}/reinitialiser-double-authentification', name: 'reset_two_factor', options: ['methods' => ['POST']])]
    public function resetTwoFactor(AdminContext $context, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        // EasyAdmin n'applique pas setPermission() aux actions personnalisées : vérification explicite
        $this->denyAccessUnlessGranted(AdminRole::SuperAdmin->value);
        $admin = $context->getEntity()->getInstance();
        \assert($admin instanceof AdminUser);
        $this->denyUnlessSameOrigin($request);
        $this->denyOwnAccount($admin);

        $this->twoFactor->reset($admin);
        $this->audit->record(AuditAction::ResetTwoFactor, $admin);
        $this->addFlash('success', \sprintf('Double authentification réinitialisée pour %s.', $admin->getEmail()));

        return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl());
    }

    private function isCurrentAdmin(AdminUser $admin): bool
    {
        $current = $this->getUser();

        return $current instanceof AdminUser && $current->getId() === $admin->getId();
    }

    private function denyOwnAccount(AdminUser $admin): void
    {
        if ($this->isCurrentAdmin($admin)) {
            throw new AccessDeniedException('Un compte ne peut pas modifier ni supprimer son propre accès depuis cet écran.');
        }
    }
}
