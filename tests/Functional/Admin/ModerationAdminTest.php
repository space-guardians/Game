<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\Conversation;
use App\Entity\Empire;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Exception\Chat\ChatRefused;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Factory\UserFactory;
use App\Repository\EmpireRepository;
use App\Service\Chat\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Sanctions depuis la fiche joueur du panneau (§4.13, §5.6.1, rôle Moderator) : avertissement, sourdine du chat,
 * bannissement (connexion refusée, session en cours fermée) et levée, notés au journal des actions.
 */
final class ModerationAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-15 18:00:00');
    }

    public function testModeratorWarnsMutesBansAndLiftsTheBan(): void
    {
        $empire = EmpireFactory::createOne(['name' => 'Fauteur', 'user' => UserFactory::new(['email' => 'fauteur@exemple.fr'])]);
        $moderator = AdminUserFactory::createOne(['role' => AdminRole::Moderator]);
        $this->client->loginUser($moderator, 'admin');
        $file = \sprintf('/admin/joueurs/%d', $empire->getId());

        $this->client->request('GET', $file);
        $this->client->submitForm('Avertir', ['message' => 'Merci de rester courtois dans le chat.']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Avertissement envoyé.');
        self::assertNotNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(Conversation::class)->findOneBy(['subject' => 'Avertissement de la modération']));

        $this->client->submitForm('Mettre en sourdine', ['hours' => '6']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#moderation', 'Chat en sourdine jusqu’au 16/10/2026 00:00.');
        try {
            self::getContainer()->get(Chat::class)->post($this->reload($empire), Chat::PUBLIC_CHANNEL, 'Encore moi');
            self::fail('Message de chat accepté malgré la sourdine.');
        } catch (ChatRefused $refusal) {
            self::assertSame('La modération vous a réduit au silence dans le chat jusqu’au 16/10/2026 à 00:00.', $refusal->getMessage());
        }

        $this->client->submitForm('Bannir', ['reason' => 'Insultes répétées', 'days' => '3']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#moderation', 'Banni jusqu’au 18/10/2026 18:00 : Insultes répétées');
        self::assertTrue($this->reload($empire)->getUser()->isBannedAt(new \DateTimeImmutable('2026-10-15 18:00')));

        $this->client->submitForm('Lever le bannissement');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Bannissement levé.');
        self::assertFalse($this->reload($empire)->getUser()->isBannedAt(new \DateTimeImmutable('2026-10-15 18:00')));

        $sanctions = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AdminAuditLog::class)->findBy(['action' => AuditAction::Sanction]);
        self::assertCount(4, $sanctions);
    }

    public function testABannedPlayerIsLoggedOutAndCannotLogIn(): void
    {
        $empire = EmpireFactory::createOne(['user' => UserFactory::new(['email' => 'banni@exemple.fr'])]);
        $this->client->loginUser($empire->getUser());
        $this->client->request('GET', '/flotte');
        self::assertResponseIsSuccessful();

        $user = $this->reload($empire)->getUser();
        $user->ban(new \DateTimeImmutable('2026-10-15 17:00'), null, 'Triche');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        // Session en cours : déconnecté à la requête suivante
        $this->client->request('GET', '/flotte');
        self::assertResponseRedirects('/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Ce compte est banni définitivement. Motif : Triche');

        // Nouvelle connexion refusée
        $this->client->submitForm('Reprendre le commandement', ['email' => 'banni@exemple.fr', 'password' => UserFactory::DEFAULT_PASSWORD]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Ce compte est banni définitivement. Motif : Triche');
    }

    private function reload(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EmpireRepository::class)->find($empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }
}
