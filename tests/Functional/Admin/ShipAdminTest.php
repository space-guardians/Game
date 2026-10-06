<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\ShipClass;
use App\Entity\ShipType;
use App\Enum\Admin\AdminRole;
use App\Enum\Fleet\ShipCategory;
use App\Factory\AdminUserFactory;
use App\Repository\ShipClassRepository;
use App\Repository\ShipTypeRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Types et classes de vaisseaux dans le panneau (§4.5, §5.6.1) : contenu de jeu réglable par le game design.
 */
final class ShipAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testStartingContentGivesEveryMilitaryTypeAClass(): void
    {
        $types = self::getContainer()->get(ShipTypeRepository::class)->findAllOrdered();

        self::assertSame(
            ['small_cargo', 'large_cargo', 'colony_ship', 'recycler', 'espionage_probe', 'light_fighter', 'heavy_fighter', 'cruiser', 'battleship', 'destroyer', 'guardian'],
            array_map(static fn(ShipType $type): string => $type->getCode(), $types),
        );
        foreach ($types as $type) {
            self::assertSame($type->isMilitary(), null !== $type->getShipClass(), $type->getCode());
            self::assertNotNull($type->getDrive(), $type->getCode());
        }
        self::assertSame('interceptor', $this->type('light_fighter')->getShipClass()?->getCode());
        self::assertSame('Gardien', $this->type('guardian')->getName());
    }

    public function testModeratorCannotSeeShips(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/vaisseaux');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/classes-vaisseaux');
        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerTunesShipType(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $id = $this->type('cruiser')->getId();

        $this->client->request('GET', '/admin/vaisseaux');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(11, 'table tbody tr');

        $this->client->request('GET', '/admin/vaisseaux/' . $id . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', [
            'ShipType[attack]' => '450',
            'ShipType[fuelCapacity]' => '7000',
        ]);

        self::assertResponseRedirects();
        $cruiser = $this->type('cruiser');
        self::assertSame(450, $cruiser->getAttack());
        self::assertSame(7000, $cruiser->getFuelCapacity());
    }

    public function testMilitaryTypeRequiresAClass(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/vaisseaux/new');
        $this->client->submitForm('Créer', [
            'ShipType[code]' => 'corvette',
            'ShipType[name]' => 'Corvette',
            'ShipType[category]' => ShipCategory::Military->value,
            'ShipType[structure]' => '8000',
            'ShipType[speed]' => '11000',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un vaisseau militaire appartient à exactement une classe de combat.');

        $this->client->submitForm('Créer', [
            'ShipType[code]' => 'corvette',
            'ShipType[name]' => 'Corvette',
            'ShipType[category]' => ShipCategory::Military->value,
            'ShipType[shipClass]' => (string) $this->shipClass('interceptor')->getId(),
            'ShipType[structure]' => '8000',
            'ShipType[speed]' => '11000',
        ]);
        self::assertResponseRedirects();
        self::assertSame('Intercepteur', $this->type('corvette')->getShipClass()?->getName());
    }

    public function testShipTypesCannotBeDeleted(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/vaisseaux/' . $this->type('recycler')->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.action-delete');
    }

    public function testClassInUseIsNotDeleted(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $interceptor = $this->shipClass('interceptor');

        $this->deleteClass($interceptor);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'encore portée par 1 type(s) de vaisseau');
        self::assertNotNull(self::getContainer()->get(ShipClassRepository::class)->findOneByCode('interceptor'));
    }

    public function testGameDesignerAddsAndDeletesUnusedClass(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/classes-vaisseaux/new');
        $this->client->submitForm('Créer', [
            'ShipClass[code]' => 'artillery',
            'ShipClass[name]' => 'Artillerie',
        ]);
        self::assertResponseRedirects();
        $artillery = $this->shipClass('artillery');

        $this->deleteClass($artillery);

        self::assertResponseRedirects();
        self::assertNull(self::getContainer()->get(ShipClassRepository::class)->findOneByCode('artillery'));
    }

    /** Suppression depuis la fiche, avec le jeton du formulaire de confirmation */
    private function deleteClass(ShipClass $class): void
    {
        $crawler = $this->client->request('GET', '/admin/classes-vaisseaux/' . $class->getId());
        $deleteUrl = (string) $crawler->filter('[data-action-name="delete"]')->first()->attr('href');
        $token = (string) $crawler->filter('input[name="token"]')->first()->attr('value');

        $this->client->request('POST', $deleteUrl, ['token' => $token]);
    }

    private function type(string $code): ShipType
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
        \assert($type instanceof ShipType);

        return $type;
    }

    private function shipClass(string $code): ShipClass
    {
        $class = self::getContainer()->get(ShipClassRepository::class)->findOneByCode($code);
        \assert($class instanceof ShipClass);

        return $class;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
