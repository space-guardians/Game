<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class RegistrationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Comme un navigateur : la protection CSRF sans état vérifie l'origine de la requête
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testRegistersLogsInAndSendsWelcomeEmail(): void
    {
        $this->register('Nouveau.Gardien@Exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('nouveau.gardien@exemple.fr');
        self::assertInstanceOf(User::class, $user);
        self::assertNotSame(UserFactory::DEFAULT_PASSWORD, $user->getPassword());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'nouveau.gardien@exemple.fr');
        self::assertEmailSubjectContains($email, 'Bienvenue parmi les Gardiens');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Connecté en tant que nouveau.gardien@exemple.fr');
    }

    public function testRefusesAlreadyRegisteredEmail(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        $this->register('GARDIEN@exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Un compte existe déjà avec cette adresse e-mail.');
        self::assertQueuedEmailCount(0);
    }

    public function testRefusesWeakPassword(): void
    {
        $this->register('gardien@exemple.fr', 'motdepasse12');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'trop facile à deviner');
    }

    public function testRequiresRulesAcceptance(): void
    {
        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, acceptRules: false);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Vous devez accepter les règles du jeu.');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('gardien@exemple.fr'));
    }

    private function register(string $email, string $password, bool $acceptRules = true): void
    {
        $this->client->request('GET', '/inscription');
        $form = $this->client->getCrawler()->selectButton('Fonder mon empire')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => $password,
        ]);
        if ($acceptRules) {
            $checkbox = $form['registration_form[acceptRules]'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $this->client->submit($form);
    }
}
