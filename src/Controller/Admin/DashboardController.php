<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Enum\Admin\AdminRole;
use App\Service\Admin\AdminIndicators;
use App\Service\Admin\AdminTwoFactor;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\GrayScale;
use EasyCorp\Bundle\EasyAdminBundle\Config\Theme;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Scheb\TwoFactorBundle\Controller\FormController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Panneau d'administration (§5.6). Les sections arrivent au fil des phases.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminIndicators $indicators,
    ) {}

    /** Indicateurs clés (§5.6.1) ; les alertes d'exploitation ne s'affichent qu'à partir du rôle Admin */
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'indicators' => $this->indicators->current(),
            'late_after' => AdminIndicators::LATE_AFTER,
        ]);
    }

    /**
     * Connexion au panneau, distincte de celle des joueurs (pare-feu « admin »). Déclarée dans le tableau de
     * bord pour hériter de son thème : /admin/connexion, route « admin_login ».
     */
    #[AdminRoute(path: '/connexion', name: 'login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('admin');
        }

        return $this->render('@EasyAdmin/page/login.html.twig', [
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'last_username' => $authenticationUtils->getLastUsername(),
            'csrf_token_intention' => 'authenticate',
            'target_path' => $this->generateUrl('admin'),
            'username_label' => 'Adresse e-mail',
            'password_label' => 'Mot de passe',
            'sign_in_label' => 'Se connecter',
            'username_parameter' => 'email',
            'password_parameter' => 'password',
        ]);
    }

    /** Interceptée par le pare-feu « admin » (security.yaml) : /admin/deconnexion, route « admin_logout » */
    #[AdminRoute(path: '/deconnexion', name: 'logout')]
    public function logout(): never
    {
        throw new \LogicException('La déconnexion est gérée par le pare-feu.');
    }

    /** Saisie du code TOTP après le mot de passe (formulaire du bundle scheb/2fa, dans le thème du panneau) */
    #[AdminRoute(path: '/double-authentification', name: '2fa_login')]
    public function twoFactorForm(
        Request $request,
        #[Autowire(service: 'scheb_two_factor.form_controller')]
        FormController $twoFactorForm,
    ): Response {
        return $twoFactorForm->form($request);
    }

    /** Interceptée par le pare-feu « admin » : vérification du code TOTP */
    #[AdminRoute(path: '/double-authentification/verification', name: '2fa_check')]
    public function twoFactorCheck(): never
    {
        throw new \LogicException('La vérification du code est gérée par le pare-feu.');
    }

    /**
     * Activation obligatoire de la double authentification (AdminSessionSubscriber y redirige tant qu'elle
     * n'est pas faite) : QR code à scanner, puis premier code pour confirmer.
     */
    #[AdminRoute(path: '/double-authentification/activation', name: '2fa_setup')]
    public function twoFactorSetup(Request $request, AdminTwoFactor $twoFactor): Response
    {
        $admin = $this->getUser();
        \assert($admin instanceof AdminUser);
        if ($admin->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('admin');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_2fa_setup', (string) $request->request->get('_token'))) {
                $error = 'La page a expiré : recommencez.';
            } elseif ($twoFactor->confirmEnrollment($admin, (string) $request->request->get('code'))) {
                $this->addFlash('success', 'Double authentification activée.');

                return $this->redirectToRoute('admin');
            } else {
                $error = 'Code incorrect : saisissez le code à 6 chiffres affiché par votre application.';
            }
        }

        return $this->render('admin/security/two_factor_setup.html.twig', [
            ...$twoFactor->prepareEnrollment($admin),
            // « error » est réservé au gabarit de connexion d'EasyAdmin (erreur d'authentification)
            'setup_error' => $error,
        ], new Response(status: null === $error ? 200 : 422));
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<span class="sg-admin-brand">SPACE <b>GUARDIANS</b></span>')
            ->setFaviconPath('images/favicon.svg')
            ->setTranslationDomain('admin')
            ->setDefaultColorScheme(ColorScheme::DARK)
            // Charte graphique : or pour l'action principale, angles droits, gris bleutés
            ->setTheme(Theme::new()->primaryColor('#F2B84B')->radius('none')->grays(GrayScale::SLATE));
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('styles/admin.css')->addAssetMapperEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord');

        yield MenuItem::section('Joueurs')->setPermission(AdminRole::Moderator->value);
        yield MenuItem::linkTo(PlayerCrudController::class, 'Joueurs')->setPermission(AdminRole::Moderator->value);

        yield MenuItem::section('Univers')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(GalaxyCrudController::class, 'Galaxies')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(StarSystemCrudController::class, 'Systèmes')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(PlanetCrudController::class, 'Planètes')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(GalaxyShapeTemplateCrudController::class, 'Gabarits de forme')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(GalaxyGenerationCrudController::class, 'Générations')->setPermission(AdminRole::GameDesigner->value);

        yield MenuItem::section('Contenu de jeu')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(BuildingTypeCrudController::class, 'Bâtiments')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(TechnologyCrudController::class, 'Technologies')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(PrerequisiteCrudController::class, 'Prérequis')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(ShipTypeCrudController::class, 'Vaisseaux')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(ShipClassCrudController::class, 'Classes de vaisseaux')->setPermission(AdminRole::GameDesigner->value);

        yield MenuItem::section('Exploitation')->setPermission(AdminRole::Admin->value);
        yield MenuItem::linkToRoute('Files de messages', null, 'admin_messenger_index')->setPermission(AdminRole::Admin->value);
        yield MenuItem::linkTo(ScheduledEventCrudController::class, 'Événements planifiés')->setPermission(AdminRole::Admin->value);
        yield MenuItem::linkToRoute('Historique des données', null, 'admin_entity_history_index')->setPermission(AdminRole::Admin->value);
        yield MenuItem::linkTo(AdminAuditLogCrudController::class, 'Journal des actions')->setPermission(AdminRole::Admin->value);
        yield MenuItem::linkTo(AdminUserCrudController::class, 'Comptes d’administration')->setPermission(AdminRole::SuperAdmin->value);
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->setName($user->getUserIdentifier());
    }
}
