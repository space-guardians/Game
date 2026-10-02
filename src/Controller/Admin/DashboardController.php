<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminRole;
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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Panneau d'administration (§5.6). Les sections arrivent au fil des phases.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
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
        return Assets::new()->addCssFile('styles/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord');

        yield MenuItem::section('Univers')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(GalaxyCrudController::class, 'Galaxies')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(StarSystemCrudController::class, 'Systèmes')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(PlanetCrudController::class, 'Planètes')->setPermission(AdminRole::GameDesigner->value);
        yield MenuItem::linkTo(GalaxyShapeTemplateCrudController::class, 'Gabarits de forme')->setPermission(AdminRole::GameDesigner->value);
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->setName($user->getUserIdentifier());
    }
}
