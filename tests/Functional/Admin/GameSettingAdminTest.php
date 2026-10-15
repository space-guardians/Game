<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\GameSetting;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Model\Economy\EconomySettings;
use App\Repository\GameSettingRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Paramètres globaux du jeu (§5.6.1) : la vitesse d'univers se règle dans le panneau, dès le rôle GameDesigner, et
 * s'applique aux réglages de l'économie.
 */
final class GameSettingAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotTuneTheGame(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/parametres');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerChangesTheUniverseSpeed(): void
    {
        $setting = self::getContainer()->get(GameSettingRepository::class)->findOneBy(['code' => GameSetting::UNIVERSE_SPEED]);
        \assert($setting instanceof GameSetting);
        self::assertSame(1.0, self::getContainer()->get(EconomySettings::class)->universeSpeed);
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');

        $this->client->request('GET', '/admin/parametres');
        self::assertSelectorTextContains('table', 'Vitesse d’univers');
        $this->client->request('GET', \sprintf('/admin/parametres/%d/edit', $setting->getId()));
        $this->client->submitForm('Sauvegarder les modifications', ['GameSetting[value]' => '0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La valeur doit être strictement positive.');

        $this->client->submitForm('Sauvegarder les modifications', ['GameSetting[value]' => '2.5']);
        self::assertResponseRedirects();

        // Prise en compte par les réglages de l'économie au démarrage suivant (requête web, worker relancé)
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        self::assertSame(2.5, self::getContainer()->get(EconomySettings::class)->universeSpeed);
    }
}
