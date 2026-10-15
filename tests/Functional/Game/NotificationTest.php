<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Enum\Notification\NotificationType;
use App\Factory\EmpireFactory;
use App\Repository\NotificationRepository;
use App\Service\Notification\Notifications;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use App\Twig\Components\MessageComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Notifications » (§4.12.3) : badge des non lues dans la navigation, historique marqué lu à l'affichage,
 * publication avec la réponse d'une requête.
 */
final class NotificationTest extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testBadgeAndHistoryThenEverythingIsRead(): void
    {
        $empire = EmpireFactory::createOne();
        $notifications = self::getContainer()->get(Notifications::class);
        $notifications->notify($empire, NotificationType::BuildingCompleted, 'Mine de métal niveau 2 achevée.', 'app_buildings');
        $notifications->notify($empire, NotificationType::IncomingAttack, 'Attaque entrante sur la planète mère.', 'app_fleet');
        $notifications->discard();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($empire->getUser());

        $this->client->request('GET', '/flotte');
        self::assertSelectorTextContains('#sg-unread-notifications', '2');

        $this->client->request('GET', '/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Notifications');
        self::assertSelectorTextContains('#notifications-list', 'Attaque entrante sur la planète mère.');
        self::assertSelectorTextContains('#notifications-list', 'Mine de métal niveau 2 achevée.');
        self::assertSelectorCount(2, '#notifications-list .is-unread');
        self::assertSelectorExists('#notifications-list a[href="/batiments"]');
        // Vues : le badge retombe à zéro
        self::assertSelectorTextSame('#sg-unread-notifications', '');
        self::assertSame(0, self::getContainer()->get(NotificationRepository::class)->countUnread($this->reload($empire)));

        $this->client->request('GET', '/notifications');
        self::assertSelectorNotExists('#notifications-list .is-unread');
    }

    public function testAMessageSentFromTheComposerIsPushedToTheRecipient(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $composer = $this->createLiveComponent(MessageComposer::class, [], $this->client)->actingAs($ana->getUser());

        $composer->set('recipients', 'Bob')->set('body', 'Bonjour Bob')->call('send');

        $pushed = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), 'Nouveau message de Ana')));
        self::assertCount(1, $pushed);
        self::assertSame(['/empire/' . $bob->getId()], $pushed[0]->getTopics());
    }

    private function reload(Empire $empire): Empire
    {
        $found = self::getContainer()->get(EntityManagerInterface::class)->find(Empire::class, $empire->getId());
        \assert($found instanceof Empire);

        return $found;
    }
}
