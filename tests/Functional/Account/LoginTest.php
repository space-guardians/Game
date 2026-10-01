<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class LoginTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // Les tentatives de connexion sont comptées dans un cache qui survit d'un test à l'autre
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testAnonymousVisitorIsSentToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('http://localhost/connexion');
    }

    public function testLogsInWithValidCredentialsWhateverTheEmailCase(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        $this->login('Gardien@Exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('http://localhost/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Connecté en tant que gardien@exemple.fr');
    }

    public function testRejectsWrongPassword(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        $this->login('gardien@exemple.fr', 'mauvais-mot-de-passe');

        self::assertResponseRedirects('http://localhost/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Identifiants invalides.');
        self::assertInputValueSame('email', 'gardien@exemple.fr');
    }

    public function testBlocksTemporarilyAfterTooManyAttempts(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('gardien@exemple.fr', 'mauvais-mot-de-passe');
        }
        // Même le bon mot de passe est refusé pendant le blocage
        $this->login('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD);
        $this->client->followRedirect();

        self::assertSelectorTextContains('[role="alert"]', 'Trop de tentatives de connexion échouées, veuillez réessayer dans 15 minutes.');
    }

    public function testLogsOut(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/deconnexion');
        self::assertResponseRedirects('http://localhost/connexion');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('http://localhost/connexion');
    }

    public function testLoggedInUserSkipsLoginAndRegistrationPages(): void
    {
        $this->client->loginUser(UserFactory::createOne());

        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/inscription');
        self::assertResponseRedirects('/');
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Reprendre le commandement', ['email' => $email, 'password' => $password]);
    }
}
