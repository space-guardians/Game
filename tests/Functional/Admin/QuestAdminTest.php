<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\QuestTemplate;
use App\Enum\Admin\AdminRole;
use App\Enum\Exploration\QuestResolution;
use App\Factory\AdminUserFactory;
use App\Repository\QuestTemplateRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Gabarits de quêtes d'exploration dans le panneau (§4.6.4, §5.6.1) : contenu réglable par le game design.
 */
final class QuestAdminTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testStartingContentChainsTheDistressSignal(): void
    {
        $signal = $this->quest('distress_signal');

        self::assertTrue($signal->isPlayerChoice());
        self::assertSame(['Enquêter', 'Ignorer le signal'], $signal->getOutcomes()->map(static fn($outcome): string => $outcome->getLabel())->getValues());
        $investigate = $signal->getOutcomes()->first();
        \assert(false !== $investigate);
        self::assertSame('relief_convoy', $investigate->getNextQuest()?->getCode());
        self::assertSame(0, $this->quest('relief_convoy')->getChance());
    }

    public function testModeratorCannotSeeQuests(): void
    {
        $this->loginAs(AdminRole::Moderator);

        $this->client->request('GET', '/admin/quetes');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerCreatesAQuestWithItsOutcomes(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/quetes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Signal de détresse');

        $crawler = $this->client->request('GET', '/admin/quetes/new');
        $form = $crawler->selectButton('Créer')->form();
        $values = $form->getPhpValues();
        $values['QuestTemplate']['code'] = 'pirate_ambush';
        $values['QuestTemplate']['name'] = 'Embuscade pirate';
        $values['QuestTemplate']['text'] = 'Des pirates surgissent d’un champ d’astéroïdes.';
        $values['QuestTemplate']['resolution'] = QuestResolution::Automatic->value;
        $values['QuestTemplate']['chance'] = '5';
        $values['QuestTemplate']['outcomes'] = [
            ['label' => 'Repoussés', 'text' => 'Les pirates fuient.', 'weight' => '2', 'metal' => '1000', 'crystal' => '0', 'deuterium' => '0', 'shipLossPercent' => '0', 'nextQuest' => ''],
            ['label' => 'Pertes', 'text' => 'La flotte se replie.', 'weight' => '1', 'metal' => '0', 'crystal' => '0', 'deuterium' => '0', 'shipLossPercent' => '20', 'nextQuest' => (string) $this->quest('derelict_convoy')->getId()],
        ];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $quest = $this->quest('pirate_ambush');
        self::assertSame(5, $quest->getChance());
        self::assertCount(2, $quest->getOutcomes());
        $losses = $quest->getOutcomes()[1];
        \assert(null !== $losses);
        self::assertSame(20, $losses->getShipLossPercent());
        self::assertSame('derelict_convoy', $losses->getNextQuest()?->getCode());

        $crawler = $this->client->request('GET', '/admin/quetes/' . $quest->getId());
        // Séparateur des milliers : espace fine insécable
        self::assertMatchesRegularExpression('/\+1\D000 métal/u', $crawler->filter('body')->text());
    }

    public function testAQuestNeedsAnOutcome(): void
    {
        $this->loginAs(AdminRole::GameDesigner);

        $this->client->request('GET', '/admin/quetes/new');
        $this->client->submitForm('Créer', [
            'QuestTemplate[code]' => 'vide',
            'QuestTemplate[name]' => 'Vide',
            'QuestTemplate[text]' => 'Rien.',
            'QuestTemplate[chance]' => '150',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une quête a au moins une issue.');
        self::assertSelectorTextContains('body', 'La probabilité va de 0 à 100 %.');
    }

    private function quest(string $code): QuestTemplate
    {
        $quest = self::getContainer()->get(QuestTemplateRepository::class)->findOneByCode($code);
        \assert($quest instanceof QuestTemplate);

        return $quest;
    }

    private function loginAs(AdminRole $role): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => $role]), 'admin');
    }
}
