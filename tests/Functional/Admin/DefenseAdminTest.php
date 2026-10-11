<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Repository\ShipTypeRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Types de défenses dans le panneau (§4.8, §5.6.1) : écran à part des vaisseaux.
 */
final class DefenseAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotSeeDefenses(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/defenses');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDefensesAndShipsHaveTheirOwnScreens(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/defenses');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(8, 'table tbody tr');
        self::assertSelectorTextContains('table', 'Canon de Gauss');

        $this->client->request('GET', '/admin/vaisseaux');
        self::assertSelectorCount(11, 'table tbody tr');
        self::assertSelectorTextNotContains('table', 'Canon de Gauss');
    }

    public function testGameDesignerCreatesADefense(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/defenses/new');
        $this->client->submitForm('Créer', [
            'ShipType[code]' => 'missile_battery',
            'ShipType[name]' => 'Batterie de missiles',
            'ShipType[attack]' => '120',
            'ShipType[shield]' => '30',
            'ShipType[structure]' => '3000',
        ]);

        self::assertResponseRedirects();
        $battery = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('missile_battery');
        self::assertTrue($battery?->isDefense());
        self::assertSame(0, $battery->getSpeed());
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
