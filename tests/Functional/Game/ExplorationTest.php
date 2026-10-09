<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\ExplorationEventInstance;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\Exploration\ExplorationEventStatus;
use App\Factory\EmpireFactory;
use App\Factory\QuestOutcomeFactory;
use App\Factory\QuestTemplateFactory;
use App\Repository\ShipTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Exploration » (§4.6.4) : journal des événements et décisions attendues.
 */
final class ExplorationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testEmptyJournalExplainsExploration(): void
    {
        $this->client->loginUser(EmpireFactory::createOne()->getUser());

        $this->client->request('GET', '/exploration');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Exploration');
        self::assertSelectorTextContains('main', 'Aucun événement pour l’instant.');
    }

    public function testPlayerDecidesAndTheFleetIsNotified(): void
    {
        [$empire, $fleet] = $this->fleetAwaiting();
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains('#flottes', 'Exploration : « Signal de détresse » attend votre décision');

        $this->client->request('GET', '/exploration');
        self::assertSelectorTextContains('#evenements', 'Un signal faible.');
        self::assertSelectorTextContains('#evenements', 'En attente de votre décision');
        $this->client->submitForm('Enquêter');

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-flash, main', '« Signal de détresse » : Enquêter.');
        self::assertSelectorTextContains('#evenements', 'Une épave pleine de cristal.');
        self::assertSelectorTextContains('#evenements', '+300 cristal');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $fleet = $entityManager->find(Fleet::class, $fleet->getId());
        self::assertSame(300.0, $fleet?->getCargo()->crystal);
    }

    public function testAnotherEmpireCannotDecide(): void
    {
        [, , $event] = $this->fleetAwaiting();
        $this->client->loginUser(EmpireFactory::createOne()->getUser());

        $this->client->request('POST', '/exploration/' . $event->getId() . '/choisir', ['outcome' => 1, '_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDispatchScreenOffersExploration(): void
    {
        [$empire, $fleet] = $this->fleetAwaiting();
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/flotte/' . $fleet->getId() . '/envoyer');

        self::assertSelectorExists('select[name="steps[0][action]"] option[value="explore"]');
    }

    /** @return array{Empire, Fleet, ExplorationEventInstance} */
    private function fleetAwaiting(): array
    {
        $template = QuestTemplateFactory::new()->choice()->create([
            'name' => 'Signal de détresse',
            'text' => 'Un signal faible.',
            'outcomes' => [
                QuestOutcomeFactory::new(['label' => 'Enquêter', 'text' => 'Une épave pleine de cristal.', 'crystal' => 300]),
                QuestOutcomeFactory::new(['label' => 'Ignorer']),
            ],
        ]);
        $empire = EmpireFactory::createOne();
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('small_cargo');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, 'Éclaireurs', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-09 10:00'));
        $fleet->addShips($type, 1);
        $event = new ExplorationEventInstance($template, $empire, $fleet, $fleet->getLocation(), 'système 1:1', new \DateTimeImmutable('+1 hour'));
        self::assertSame(ExplorationEventStatus::AwaitingChoice, $event->getStatus());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->persist($event);
        $entityManager->flush();

        return [$empire, $fleet, $event];
    }
}
