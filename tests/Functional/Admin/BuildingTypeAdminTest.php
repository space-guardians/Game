<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\BuildingType;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Repository\BuildingTypeRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Types de bâtiments dans le panneau (§5.6.1) : réglage des coûts et effets par le game design.
 */
final class BuildingTypeAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotSeeBuildings(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/batiments');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerSeesLevelsPreview(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/batiments/' . $this->mine()->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mine de métal');
        self::assertSelectorCount(10, '.sg-admin-preview tbody tr');
        // Niveau 2 : 90 métal, 22,5 → 23 cristal
        self::assertSelectorTextContains('.sg-admin-preview tbody tr:nth-child(2)', '90');
    }

    public function testGameDesignerTunesCosts(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $id = $this->mine()->getId();

        $this->client->request('GET', '/admin/batiments/' . $id . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', [
            'BuildingType[baseCostMetal]' => '80',
            'BuildingType[costFactor]' => '1,6',
        ]);

        self::assertResponseRedirects();
        $mine = $this->mine();
        self::assertSame(80.0, $mine->getBaseCostMetal());
        self::assertSame(1.6, $mine->getCostFactor());
    }

    public function testRejectsCostFactorBelowOne(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/batiments/' . $this->mine()->getId() . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', ['BuildingType[costFactor]' => '0,9']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le facteur de coût doit être au moins 1');
    }

    public function testCannotCreateOrDeleteTypes(): void
    {
        $this->loginAs(AdminRole::SuperAdmin);

        $this->client->request('GET', '/admin/batiments/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/batiments/' . $this->mine()->getId() . '/delete');
        self::assertResponseStatusCodeSame(403);
    }

    private function mine(): BuildingType
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $mine = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('metal_mine');
        \assert(null !== $mine);

        return $mine;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
