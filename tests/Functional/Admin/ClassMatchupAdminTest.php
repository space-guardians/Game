<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Enum\Admin\AdminRole;
use App\Factory\AdminUserFactory;
use App\Repository\ClassMatchupRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Matrice des classes dans le panneau (§4.7, §5.6.1) : grille éditée par le game design.
 */
final class ClassMatchupAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testModeratorCannotSeeTheMatrix(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/matrice-classes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerEditsTheMatrix(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $crawler = $this->client->request('GET', '/admin/matrice-classes');
        self::assertResponseIsSuccessful();
        // 5 classes : grille 5 × 5
        self::assertSelectorCount(25, '#matrice tbody input');
        self::assertSame('1,50', $crawler->filter('input[name="matrix[interceptor][bomber]"]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name="matrix[support][support]"]')->attr('value'));

        $this->client->submitForm('Enregistrer la matrice', [
            'matrix[interceptor][bomber]' => '2',
            'matrix[support][capital]' => '0,5',
        ]);

        self::assertResponseRedirects('/admin/matrice-classes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Matrice enregistrée : 2 case(s) modifiée(s).');
        $matrix = self::getContainer()->get(ClassMatchupRepository::class)->matrix();
        self::assertSame(2.0, $matrix->multiplier('interceptor', 'bomber'));
        self::assertSame(0.5, $matrix->multiplier('support', 'capital'));
    }

    public function testInvalidValueIsShownAgainForCorrection(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/matrice-classes');
        $crawler = $this->client->submitForm('Enregistrer la matrice', ['matrix[bomber][capital]' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Bombardier contre Capital : un multiplicateur va de 0,1 à 10.');
        self::assertSame('0', $crawler->filter('input[name="matrix[bomber][capital]"]')->attr('value'));
    }

    public function testSavingRequiresTheSameOrigin(): void
    {
        $this->loginAs(AdminRole::GameDesigner);
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://ailleurs.example');

        $this->client->request('POST', '/admin/matrice-classes/enregistrer', ['matrix' => ['interceptor' => ['bomber' => '9']]]);

        self::assertResponseStatusCodeSame(403);
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
