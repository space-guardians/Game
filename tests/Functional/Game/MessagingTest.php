<?php

declare(strict_types=1);

namespace App\Tests\Functional\Game;

use App\Entity\Empire;
use App\Entity\Message;
use App\Factory\EmpireFactory;
use App\Repository\ConversationRepository;
use App\Service\Messaging\Messaging;
use App\Twig\Components\MessageComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;

/**
 * Écran « Messagerie » (§4.12.2, §5.7) : liste, conversation, rédaction (Live Component), actions de chaque participant.
 */
final class MessagingTest extends WebTestCase
{
    use Factories;
    use InteractsWithLiveComponents;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testComposerStartsAConversationAndReportsErrors(): void
    {
        [$ana] = $this->empires('Ana', 'Bob');
        $composer = $this->createLiveComponent(MessageComposer::class, [], $this->client)->actingAs($ana->getUser());

        $composer->set('recipients', 'Inconnu')->set('body', 'Bonjour')->call('send');
        self::assertStringContainsString('Aucun joueur ne s’appelle « Inconnu ».', $composer->render()->toString());

        $composer->set('recipients', 'Bob')->set('subject', 'Alliance ?')->set('body', 'Bonjour Bob')->call('send');
        $conversation = self::getContainer()->get(ConversationRepository::class)->findOneBy([]);
        self::assertNotNull($conversation);
        self::assertSame('Alliance ?', $conversation->getSubject());
        self::assertStringContainsString('/messagerie/' . $conversation->getId(), (string) $composer->response()->headers->get('Location'));
    }

    public function testRecipientReadsAndRepliesAndTheUnreadCounterFollows(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $conversation = self::getContainer()->get(Messaging::class)->start($ana, ['Bob'], null, 'Bonjour Bob');
        $this->client->loginUser($bob->getUser());

        $this->client->request('GET', '/messagerie');
        self::assertSelectorTextContains('.sg-sidenav [aria-current="page"]', 'Messagerie');
        self::assertSelectorTextContains('#sg-unread-messages', '1');
        self::assertSelectorTextContains('#conversations-inbox', 'Ana');
        self::assertSelectorTextContains('#conversations-inbox', 'Non lu');

        $this->client->clickLink('Ana');
        self::assertSelectorTextContains('#conversation-' . $conversation->getId() . '-messages', 'Bonjour Bob');
        // Lue à l'affichage
        self::assertSelectorTextSame('#sg-unread-messages', '');

        $reply = $this->createLiveComponent(MessageComposer::class, ['conversationId' => $conversation->getId()], $this->client)->actingAs($bob->getUser());
        $reply->set('body', 'Bonjour Ana')->call('send');
        self::assertSame(2, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Message::class)->count(['conversation' => $conversation->getId()]));
    }

    public function testGroupActionsArchiveAndDelete(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob', 'Cyd', 'Dan');
        $group = self::getContainer()->get(Messaging::class)->start($ana, ['Bob', 'Cyd'], 'Conseil', 'Ordre du jour');
        $this->client->loginUser($bob->getUser());
        $crawler = $this->client->request('GET', '/messagerie/' . $group->getId());
        self::assertSelectorTextContains('#participants', 'Ana');

        $this->client->submit($crawler->selectButton('Ajouter')->form(['names' => 'Dan']));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Ajouté(s) à la conversation : Dan.');
        self::assertSelectorTextContains('#participants', 'Dan');

        $this->client->submitForm('Archiver');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Aucune conversation pour l’instant.');
        $this->client->request('GET', '/messagerie?archives=1');
        self::assertSelectorTextContains('#conversations-archives', 'Conseil');

        $this->client->request('GET', '/messagerie/' . $group->getId());
        $this->client->submitForm('Quitter le groupe');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sg-alert', 'Vous avez quitté la conversation.');

        $this->client->request('GET', '/messagerie/' . $group->getId());
        self::assertSelectorTextContains('main', 'Vous avez quitté cette conversation');
        $this->client->submitForm('Supprimer de mon côté');
        $this->client->request('GET', '/messagerie?archives=1');
        self::assertSelectorTextContains('main', 'Aucune conversation archivée.');
    }

    public function testOutsidersCannotOpenAConversation(): void
    {
        [$ana] = $this->empires('Ana', 'Bob', 'Eve');
        $conversation = self::getContainer()->get(Messaging::class)->start($ana, ['Bob'], null, 'Secret');
        $this->client->loginUser(EmpireFactory::find(['name' => 'Eve'])->getUser());

        $this->client->request('GET', '/messagerie/' . $conversation->getId());

        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }
}
