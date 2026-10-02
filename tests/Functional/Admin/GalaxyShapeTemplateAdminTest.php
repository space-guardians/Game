<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Admin\AdminRole;
use App\Entity\GalaxyShapeTemplate;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyShapeTemplateFactory;
use App\Repository\GalaxyShapeTemplateRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

final class GalaxyShapeTemplateAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotSeeTemplates(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/gabarits-de-forme');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerCreatesTemplate(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/gabarits-de-forme/new');
        $this->client->submitForm('Créer', [
            'GalaxyShapeTemplate[name]' => 'Spirale à six bras',
            'GalaxyShapeTemplate[arms]' => '6',
            'GalaxyShapeTemplate[armWidth]' => '0,4',
        ]);

        self::assertResponseRedirects();
        $template = self::getContainer()->get(GalaxyShapeTemplateRepository::class)->findOneByName('Spirale à six bras');
        self::assertInstanceOf(GalaxyShapeTemplate::class, $template);
        self::assertSame(6, $template->getArms());
        self::assertSame(0.4, $template->getArmWidth());
    }

    public function testRejectsShapeOutsideAlgorithmBounds(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/gabarits-de-forme/new');
        $this->client->submitForm('Créer', ['GalaxyShapeTemplate[name]' => 'Trop de bras', 'GalaxyShapeTemplate[arms]' => '13']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une galaxie spirale a entre 1 et 12 branches.');
    }

    public function testDetailShowsPreviewOfTheShape(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $template = GalaxyShapeTemplateFactory::createOne(['name' => 'Classique']);

        $crawler = $this->client->request('GET', '/admin/gabarits-de-forme/' . $template->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gabarit « Classique »');
        self::assertCount(700, $crawler->filter('.sg-admin-preview circle'));
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
