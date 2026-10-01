<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class ResetPasswordTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private const string NEW_PASSWORD = 'Nouvelle-Balise-Gardienne-7';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // Les tentatives de connexion sont comptées dans un cache qui survit d'un test à l'autre
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testResetsPasswordThroughEmailedLink(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        $this->requestReset('gardien@exemple.fr');
        self::assertResponseRedirects('/mot-de-passe-oublie/verifier-vos-e-mails');
        self::assertQueuedEmailCount(1);

        $this->client->request('GET', $this->resetLink());
        // Le jeton quitte l'URL pour la session
        self::assertResponseRedirects('/mot-de-passe-oublie/reinitialiser');
        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer', [
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);
        self::assertResponseRedirects('/connexion');

        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Reprendre le commandement', ['email' => 'gardien@exemple.fr', 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('http://localhost/');
    }

    public function testDoesNotRevealWhetherAnAccountExists(): void
    {
        $this->requestReset('inconnu@exemple.fr');

        self::assertResponseRedirects('/mot-de-passe-oublie/verifier-vos-e-mails');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Si un compte correspond à cette adresse');
    }

    public function testLinkWorksOnlyOnce(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);
        $this->requestReset('gardien@exemple.fr');
        $link = $this->resetLink();

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->client->submitForm('Enregistrer', [
            'change_password_form[plainPassword][first]' => self::NEW_PASSWORD,
            'change_password_form[plainPassword][second]' => self::NEW_PASSWORD,
        ]);

        $this->client->request('GET', $link);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testLinkExpiresAfterOneHour(): void
    {
        $clock = self::mockTime('2026-10-01 10:00:00');
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);
        $this->requestReset('gardien@exemple.fr');

        $clock->sleep(61 * 60);
        $this->client->request('GET', $this->resetLink());
        $this->client->followRedirect();

        self::assertResponseRedirects('/mot-de-passe-oublie');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Ce lien de réinitialisation n\'est plus valide.');
    }

    private function requestReset(string $email): void
    {
        $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submitForm('Recevoir le lien', ['reset_password_request_form[email]' => $email]);
    }

    private function resetLink(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame(1, preg_match('#https?://[^/]+(/mot-de-passe-oublie/reinitialiser/[^"]+)#', (string) $email->getHtmlBody(), $matches));

        return $matches[1];
    }
}
