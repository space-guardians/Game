<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class AdminAccessTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testAnonymousVisitorIsSentToAdminLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    public function testPlayerAccountCannotEnterAdminPanel(): void
    {
        $this->client->loginUser(UserFactory::createOne(), 'main');

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    public function testPlayerCredentialsAreRejectedByAdminLogin(): void
    {
        UserFactory::createOne(['email' => 'joueur@exemple.fr']);

        $this->login('joueur@exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    public function testAdminLogsInThroughAdminLogin(): void
    {
        AdminUserFactory::createOne(['email' => 'admin@space-guardians.local', 'role' => 'ROLE_SUPER_ADMIN']);

        $this->login('Admin@Space-Guardians.local', AdminUserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'admin@space-guardians.local (Super administration)');
    }

    public function testAdminSessionDoesNotOpenPlayerArea(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(), 'admin');

        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/connexion');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function roles(): iterable
    {
        foreach (array_keys(AdminUser::ROLES) as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('roles')]
    public function testEveryAdminRoleReachesDashboard(string $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');

        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', AdminUser::ROLES[$role]);
    }

    public function testAdminLogsOut(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(), 'admin');

        $this->client->request('GET', '/admin/deconnexion');
        self::assertResponseRedirects('http://localhost/admin/connexion');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/admin/connexion');
        $this->client->submitForm('Se connecter', ['email' => $email, 'password' => $password]);
    }
}
