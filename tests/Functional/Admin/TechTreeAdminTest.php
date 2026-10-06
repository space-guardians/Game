<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Technology;
use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Repository\BuildingTypeRepository;
use App\Repository\PrerequisiteRepository;
use App\Repository\TechnologyRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Technologies et prérequis dans le panneau (§4.4, §5.6.1) : contenu de jeu réglable par le game design.
 */
final class TechTreeAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotSeeTechTree(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/technologies');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/prerequis');
        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerSeesTechnologyWithPrerequisitesAndCosts(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/technologies');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(10, 'table tbody tr');

        $this->client->request('GET', '/admin/technologies/' . $this->technology('astrophysics')->getId());
        self::assertSelectorTextContains('h1', 'Astrophysique');
        self::assertSelectorTextContains('#prerequis', 'Laboratoire de recherche niveau 3');
        self::assertSelectorTextContains('#prerequis', 'Espionnage niveau 4');
        self::assertSelectorTextContains('#prerequis', 'Propulsion à impulsion niveau 3');
        // Niveau 2 : 4 000 × 1,75
        $secondLevel = $this->client->getCrawler()->filter('.sg-admin-preview tbody tr')->eq(1)->text();
        self::assertMatchesRegularExpression('/^2 7\D000 14\D000 7\D000$/u', $secondLevel);
    }

    public function testGameDesignerTunesTechnologyCost(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $id = $this->technology('energy')->getId();

        $this->client->request('GET', '/admin/technologies/' . $id . '/edit');
        $this->client->submitForm('Sauvegarder les modifications', [
            'Technology[baseCostCrystal]' => '900',
            'Technology[costFactor]' => '2,5',
        ]);

        self::assertResponseRedirects();
        $energy = $this->technology('energy');
        self::assertSame(900.0, $energy->getBaseCostCrystal());
        self::assertSame(2.5, $energy->getCostFactor());
    }

    public function testGameDesignerAddsPrerequisite(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $robots = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('robot_factory');
        \assert(null !== $robots);

        $this->client->request('GET', '/admin/prerequis/new');
        $this->client->submitForm('Créer', [
            'Prerequisite[targetBuilding]' => (string) $robots->getId(),
            'Prerequisite[requiredTechnology]' => (string) $this->technology('computer')->getId(),
            'Prerequisite[level]' => '2',
        ]);

        self::assertResponseRedirects();
        $prerequisites = self::getContainer()->get(PrerequisiteRepository::class)->findFor($robots);
        self::assertCount(1, $prerequisites);
        self::assertSame('Usine de robots → Informatique niveau 2', (string) $prerequisites[0]);

        $this->client->request('GET', '/admin/prerequis');
        self::assertSelectorTextContains('table', 'Usine de robots');
    }

    public function testPrerequisiteNeedsExactlyOneTargetAndOneRequirement(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $energy = $this->technology('energy');

        $this->client->request('GET', '/admin/prerequis/new');
        $this->client->submitForm('Créer', [
            'Prerequisite[requiredTechnology]' => (string) $energy->getId(),
            'Prerequisite[level]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choisissez la cible');

        $this->client->submitForm('Créer', [
            'Prerequisite[targetTechnology]' => (string) $energy->getId(),
            'Prerequisite[requiredTechnology]' => (string) $energy->getId(),
            'Prerequisite[level]' => '1',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ne peut pas se requérir lui-même');

        self::assertCount(1, self::getContainer()->get(PrerequisiteRepository::class)->findFor($energy));
    }

    private function technology(string $code): Technology
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        \assert($technology instanceof Technology);

        return $technology;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
