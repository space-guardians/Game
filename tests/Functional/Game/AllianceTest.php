<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Repository\AllianceMemberRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Alliance » (§4.10) : fondation, invitation, acceptation, gestion des rôles, départ ; consultation dans le
 * panneau.
 */
final class AllianceTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testFoundInviteAcceptAndManage(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue Stellaire', 'tag' => 'lgs', 'description' => 'Entraide']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Alliance [LGS] Ligue Stellaire fondée.');
        self::assertSelectorTextContains('#alliance', 'votre rôle : fondateur');

        $this->client->submitForm('Inviter', ['name' => 'Bob']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#invitations-envoyees', 'Bob');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#invitations', '[LGS] Ligue Stellaire');
        $this->client->submitForm('Accepter');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Bienvenue dans l’alliance [LGS] Ligue Stellaire.');
        self::assertSelectorTextContains('#membres', 'Ana');
        self::assertSelectorNotExists('#invite');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Nommer diplomate');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Bob est désormais diplomate.');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorExists('#invite');
        $this->client->submitForm('Quitter l’alliance');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Vous avez quitté l’alliance.');
        self::assertNull(self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($bob));
    }

    public function testPactsAreProposedConcludedAndUnbreakable(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Proposer le pacte', ['name' => 'Bob', 'days' => '60']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Pacte de 60 jours proposé à Bob.');
        self::assertSelectorTextContains('#pactes', 'proposé un pacte de 60 jours');

        $this->client->loginUser($bob->getUser());
        $this->client->request('GET', '/alliance');
        self::assertSelectorTextContains('#pactes', 'Ana · vous propose un pacte de 60 jours');
        $this->client->submitForm('Conclure le pacte');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Pacte conclu avec Ana');
        self::assertSelectorTextContains('#pactes', 'en vigueur jusqu’au');
        // Conclu : plus de bouton pour le rompre
        self::assertSelectorNotExists('#pactes form');
    }

    public function testFounderSharesCombatReports(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue', 'tag' => 'LGS']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#partage', 'réservés aux membres engagés');

        $this->client->submitForm('Partager les rapports de combat');
        $this->client->followRedirect();

        self::assertSelectorTextContains('#partage', 'partagés avec toute l’alliance');
    }

    public function testRefusalIsShown(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');

        $this->client->submitForm('Fonder', ['name' => 'Li', 'tag' => 'LG']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.sg-alert', 'Le nom compte de 3 à 30 caractères.');
    }

    public function testModeratorsCanConsultAlliances(): void
    {
        [$ana] = $this->empires('Ana');
        $this->client->loginUser($ana->getUser());
        $this->client->request('GET', '/alliance');
        $this->client->submitForm('Fonder', ['name' => 'Ligue', 'tag' => 'LGS']);

        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');
        $this->client->request('GET', '/admin/alliances');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'LGS');
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }
}
