<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\AdminRole;
use App\Entity\AdminUser;
use App\Factory\AdminUserFactory;
use App\Repository\AdminUserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Gestion des comptes d'administration, réservée à la super administration (§5.6.2).
 */
final class AdminAccountAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testAdminCannotManageAccounts(): void
    {
        $this->loginAs(AdminRole::Admin);
        $other = AdminUserFactory::createOne();

        $this->client->request('GET', '/admin/comptes');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/comptes/' . $other->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/comptes/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/comptes/' . $other->getId() . '/reinitialiser-double-authentification');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($other)->isTotpAuthenticationEnabled());
    }

    public function testMenuShowsAccountsToSuperAdminOnly(): void
    {
        $this->loginAs(AdminRole::Admin);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextNotContains('nav', 'Comptes d’administration');

        $this->loginAs(AdminRole::SuperAdmin);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('nav', 'Comptes d’administration');
    }

    public function testSuperAdminCreatesAccountWithoutTwoFactor(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);

        $this->client->request('GET', '/admin/comptes/new');
        $this->client->submitForm('Créer', [
            'AdminUser[email]' => 'Moderation@Space-Guardians.local',
            'AdminUser[role]' => AdminRole::Moderator->value,
            'AdminUser[plainPassword]' => 'Veille-Nocturne-Andromede-9',
        ]);

        self::assertResponseRedirects();
        $created = self::getContainer()->get(AdminUserRepository::class)->findOneBy(['email' => 'moderation@space-guardians.local']);
        self::assertInstanceOf(AdminUser::class, $created);
        self::assertSame(AdminRole::Moderator, $created->getRole());
        self::assertNull($created->getPlainPassword());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($created, 'Veille-Nocturne-Andromede-9'));
        self::assertFalse($created->isTotpAuthenticationEnabled());
    }

    public function testCreationRequiresStrongPassword(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);

        $this->client->request('GET', '/admin/comptes/new');
        $this->client->submitForm('Créer', [
            'AdminUser[email]' => 'faible@space-guardians.local',
            'AdminUser[role]' => AdminRole::Moderator->value,
            'AdminUser[plainPassword]' => 'motdepasse',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'au moins 12 caractères');
    }

    public function testSuperAdminChangesRoleOfAnotherAccount(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);
        $other = AdminUserFactory::createOne(['role' => AdminRole::Moderator]);

        $this->client->request('GET', '/admin/comptes/' . $other->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['AdminUser[role]' => AdminRole::GameDesigner->value]);

        self::assertResponseRedirects();
        self::assertSame(AdminRole::GameDesigner, $this->reload($other)->getRole());
    }

    public function testSuperAdminCannotEditOrDeleteOwnAccount(): void
    {
        $me = $this->loginAs(AdminRole::SuperAdmin);

        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(\sprintf('a[href$="/admin/comptes/%d/edit"]', $me->getId())));

        $this->client->request('GET', '/admin/comptes/' . $me->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['AdminUser[role]' => AdminRole::Moderator->value]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AdminRole::SuperAdmin, $this->reload($me)->getRole());
    }

    public function testSuperAdminResetsTwoFactorOfAnotherAccount(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);
        $other = AdminUserFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/comptes/' . $other->getId());
        $this->client->submit($crawler->selectButton('Réinitialiser la double authentification')->form());

        self::assertResponseRedirects();
        self::assertFalse($this->reload($other)->isTotpAuthenticationEnabled());
    }

    public function testTwoFactorResetRefusesCrossSiteRequest(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);
        $other = AdminUserFactory::createOne();

        $this->client->request('POST', '/admin/comptes/' . $other->getId() . '/reinitialiser-double-authentification', server: [
            'HTTP_ORIGIN' => 'https://ailleurs.example',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($other)->isTotpAuthenticationEnabled());
    }

    private function loginAs(AdminRole $role): AdminUser
    {
        $admin = AdminUserFactory::createOne(['role' => $role]);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    /** Relit le compte en base, sans les modifications refusées restées en mémoire */
    private function reload(AdminUser $admin): AdminUser
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $reloaded = AdminUserFactory::repository()->find($admin->getId());
        \assert($reloaded instanceof AdminUser);

        return $reloaded;
    }
}
