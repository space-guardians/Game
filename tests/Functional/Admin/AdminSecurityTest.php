<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Double authentification obligatoire et expiration de session du panneau (§5.6.2).
 */
final class AdminSecurityTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->clock = self::mockTime('2026-10-02 09:00:00');
    }

    public function testPasswordAloneDoesNotOpenPanel(): void
    {
        AdminUserFactory::createOne(['email' => 'admin@space-guardians.local']);
        $this->login('admin@space-guardians.local');

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('http://localhost/admin/double-authentification');
    }

    public function testWrongCodeIsRejected(): void
    {
        AdminUserFactory::createOne(['email' => 'admin@space-guardians.local']);
        $this->login('admin@space-guardians.local');
        $this->client->request('GET', '/admin/double-authentification');

        $this->client->submitForm('Vérifier', ['_auth_code' => $this->otherCode(AdminUserFactory::totpCode($this->clock->now()))]);
        $this->client->followRedirect();

        self::assertSelectorExists('.alert-danger');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/admin/double-authentification');
    }

    public function testAdminWithoutTwoFactorMustEnrollFirst(): void
    {
        $admin = AdminUserFactory::new()->withoutTwoFactor()->create();
        $this->client->loginUser($admin, 'admin');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/double-authentification/activation');

        $this->client->request('GET', '/admin/comptes');
        self::assertResponseRedirects('/admin/double-authentification/activation');
    }

    public function testEnrollmentRequiresValidCode(): void
    {
        $admin = AdminUserFactory::new()->withoutTwoFactor()->create();
        $this->client->loginUser($admin, 'admin');
        $crawler = $this->client->request('GET', '/admin/double-authentification/activation');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src^="data:image/svg+xml"]');
        $secret = trim($crawler->filter('.sg-admin-secret')->text());

        $code = TOTP::createFromSecret($secret)->at($this->clock->now()->getTimestamp());
        $this->client->submitForm('Activer', ['code' => $this->otherCode($code)]);
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->reload($admin)->isTotpAuthenticationEnabled());

        $this->client->submitForm('Activer', ['code' => $code]);
        self::assertResponseRedirects('/admin');
        self::assertTrue($this->reload($admin)->isTotpAuthenticationEnabled());
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testEnrollmentRejectsMissingCsrfToken(): void
    {
        $admin = AdminUserFactory::new()->withoutTwoFactor()->create();
        $this->client->loginUser($admin, 'admin');
        $this->client->request('GET', '/admin/double-authentification/activation');
        $secret = (string) $this->reload($admin)->getTotpAuthenticationConfiguration()?->getSecret();

        $this->client->request('POST', '/admin/double-authentification/activation', [
            'code' => TOTP::createFromSecret($secret)->at($this->clock->now()->getTimestamp()),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->reload($admin)->isTotpAuthenticationEnabled());
    }

    public function testSessionSurvivesActivity(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(), 'admin');
        $this->client->request('GET', '/admin');

        $this->clock->sleep(29 * 60);
        $this->client->request('GET', '/admin');
        $this->clock->sleep(29 * 60);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
    }

    public function testSessionExpiresAfterThirtyMinutesIdle(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(), 'admin');
        $this->client->request('GET', '/admin');

        $this->clock->sleep(31 * 60);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Session expirée');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    private function login(string $email): void
    {
        $this->client->request('GET', '/admin/connexion');
        $this->client->submitForm('Se connecter', ['email' => $email, 'password' => AdminUserFactory::DEFAULT_PASSWORD]);
    }

    /** Code à 6 chiffres différent du code valide */
    private function otherCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', \STR_PAD_LEFT);
    }

    private function reload(AdminUser $admin): AdminUser
    {
        $reloaded = AdminUserFactory::repository()->find($admin->getId());
        \assert($reloaded instanceof AdminUser);

        return $reloaded;
    }
}
