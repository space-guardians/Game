<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\GameEvent;
use App\Entity\ScheduledEvent;
use App\Enum\Admin\AdminRole;
use App\Enum\Event\GameEventStatus;
use App\Factory\AdminUserFactory;
use App\Factory\EmpireFactory;
use App\Repository\GameEventRepository;
use App\Service\Event\GameEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Événements de jeu dans le panneau (§4.16, §5.6.1) : création et planification, aperçu, annulation, dès le rôle
 * GameDesigner ; bannière côté joueur pendant l'événement.
 */
final class GameEventAdminTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        self::mockTime('2026-10-15 10:00:00');
    }

    public function testModeratorCannotManageEvents(): void
    {
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::Moderator]), 'admin');

        $this->client->request('GET', '/admin/evenements-de-jeu');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGameDesignerCreatesSchedulesPreviewsAndCancelsAnEvent(): void
    {
        EmpireFactory::createMany(2);
        $this->client->loginUser(AdminUserFactory::createOne(['role' => AdminRole::GameDesigner]), 'admin');

        $this->client->request('GET', '/admin');
        self::assertSelectorTextContains('#main-menu', 'Événements de jeu');
        $this->client->request('GET', '/admin/evenements-de-jeu/new');
        $this->client->submitForm('Créer', ['GameEvent[name]' => 'Sans effet']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un événement a au moins un effet.');

        $this->client->request('GET', '/admin/evenements-de-jeu/new');
        $this->client->submitForm('Créer', [
            'GameEvent[name]' => 'Pluie de météores',
            'GameEvent[description]' => 'Des fragments riches en métal tombent sur l’univers.',
            'GameEvent[startsAt]' => '2026-10-16T08:00',
            'GameEvent[endsAt]' => '2026-10-18T08:00',
            'GameEvent[productionBonus]' => '25',
        ]);
        self::assertResponseRedirects();

        $event = self::getContainer()->get(GameEventRepository::class)->findOneBy(['name' => 'Pluie de météores']);
        \assert($event instanceof GameEvent);
        self::assertSame(GameEventStatus::Scheduled, $event->getStatus());
        $wakeUps = array_filter(self::getContainer()->get(EntityManagerInterface::class)->getRepository(ScheduledEvent::class)->findAll(), static fn(ScheduledEvent $scheduled): bool => ($scheduled->getPayload()['event'] ?? null) === $event->getId());
        self::assertEqualsCanonicalizing([GameEvents::START, GameEvents::END], array_map(static fn(ScheduledEvent $scheduled): string => $scheduled->getType(), array_values($wakeUps)));

        // Aperçu : les planètes d'empires concernées
        $this->client->request('GET', \sprintf('/admin/evenements-de-jeu/%d', $event->getId()));
        self::assertSelectorTextContains('body', 'Planètes concernées (aperçu)');
        self::assertSelectorTextContains('.field-integer', '2');

        $this->client->submitForm('Annuler l’événement');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Événement « Pluie de météores » annulé.');
        self::assertSame(GameEventStatus::Cancelled, self::getContainer()->get(GameEventRepository::class)->find($event->getId())?->getStatus());
    }

    public function testPlayersSeeRunningEventsWithACountdown(): void
    {
        $empire = EmpireFactory::createOne();
        $event = new GameEvent(new \DateTimeImmutable('2026-10-15 09:00'), new \DateTimeImmutable('2026-10-15 12:00'));
        $event->setName('Pluie de météores');
        $event->setProductionBonus(25);
        $event->start();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($event);
        $entityManager->flush();
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/flotte');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#sg-game-events', 'Pluie de météores — production +25 %');
        self::assertSelectorTextContains('#sg-game-events [data-countdown-target="remaining"]', '2 h');
    }
}
