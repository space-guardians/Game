<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRefused;
use App\Factory\EmpireFactory;
use App\Model\Chat\ChatTopics;
use App\Service\Alliance\Alliances;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Chat\ChatGroups;
use App\Service\Chat\ChatPresence;
use App\Service\Messaging\IgnoredPlayers;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\Update;
use Zenstruck\Foundry\Test\Factories;

/**
 * Joueurs ignorés dans le chat (§4.12.4) : discussion privée impossible, messages masqués dans les canaux public,
 * alliance et groupe, ajout à un groupe refusé.
 */
final class IgnoredChatTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    /** @var list<string> */
    private array $channels = [Chat::PUBLIC_CHANNEL];

    /** @var list<string> */
    private array $groupIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
        self::getContainer()->get(ChatPresence::class)->clear();
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatPresence::class)->clear();
        foreach ($this->channels as $channel) {
            self::getContainer()->get(ChatBuffer::class)->clear($channel);
        }
        foreach ($this->groupIds as $id) {
            self::getContainer()->get(ChatGroups::class)->delete($id);
        }
        parent::tearDown();
    }

    public function testNoPrivateChatWithAPlayerWhoIgnoresYouNorWithOneYouIgnore(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $channel = Chat::privateChannel($ana, $bob);
        $this->channels[] = $channel;
        $this->ignored()->ignore($ana, 'Bob');

        foreach ([
            'Ana n’accepte pas vos messages.' => [fn() => $this->chat()->openPrivate($bob, 'Ana'), fn() => $this->chat()->post($bob, $channel, 'Coucou')],
            'Vous ignorez Bob : retirez ce joueur de votre liste pour lui écrire.' => [fn() => $this->chat()->openPrivate($ana, 'Bob'), fn() => $this->chat()->post($ana, $channel, 'Va-t’en')],
        ] as $expected => $attempts) {
            foreach ($attempts as $attempt) {
                try {
                    $attempt();
                    self::fail('Discussion privée ouverte malgré la liste des joueurs ignorés.');
                } catch (ChatRefused $refusal) {
                    self::assertSame($expected, $refusal->getMessage());
                }
            }
        }

        $this->ignored()->unignore($ana, (int) $bob->getId());
        self::assertSame('Bob', $this->chat()->openPrivate($ana, 'Bob')->peer);
    }

    public function testPublicMessagesOfAnIgnoredPlayerAreHiddenFromTheFeed(): void
    {
        [$ana, $bob, $cid] = $this->empires('Ana', 'Bob', 'Cid');
        $this->chat()->post($bob, Chat::PUBLIC_CHANNEL, 'Message de Bob');
        $this->chat()->post($cid, Chat::PUBLIC_CHANNEL, 'Message de Cid');

        $this->ignored()->ignore($ana, 'Bob');

        self::assertSame(['Message de Cid'], array_map(static fn($message): string => $message->body, $this->chat()->recent($ana, Chat::PUBLIC_CHANNEL)));
        self::assertCount(2, $this->chat()->recent($cid, Chat::PUBLIC_CHANNEL));
        // Le widget reçoit la liste à jour pour masquer les messages en direct
        $update = $this->lastUpdateContaining('"type":"ignored"');
        self::assertSame([ChatTopics::user($ana->getUser())], $update->getTopics());
        self::assertSame(['type' => 'ignored', 'ids' => [(int) $bob->getId()]], json_decode($update->getData(), true));
    }

    public function testAllianceAndGroupMessagesSkipThoseWhoIgnoreTheAuthor(): void
    {
        [$ana, $bob, $cid] = $this->empires('Ana', 'Bob', 'Cid');
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($ana, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($ana, 'Bob'), $bob);
        $alliances->accept($alliances->invite($ana, 'Cid'), $cid);
        $allianceChannel = (string) $this->chat()->allianceChannel($ana);
        $group = self::getContainer()->get(ChatGroups::class)->create($ana, 'Escadre');
        $this->groupIds[] = $group->id;
        $this->channels[] = $allianceChannel;
        $this->channels[] = $group->channel();
        self::getContainer()->get(ChatGroups::class)->add($ana, $group->id, 'Bob');
        self::getContainer()->get(ChatGroups::class)->add($ana, $group->id, 'Cid');
        $this->ignored()->ignore($cid, 'Bob');

        $this->chat()->post($bob, $allianceChannel, 'Alliance : Bob parle');
        $this->chat()->post($bob, $group->channel(), 'Groupe : Bob parle');

        $expected = [ChatTopics::user($ana->getUser()), ChatTopics::user($bob->getUser())];
        self::assertEqualsCanonicalizing($expected, $this->lastUpdateContaining('Alliance : Bob parle')->getTopics());
        self::assertEqualsCanonicalizing($expected, $this->lastUpdateContaining('Groupe : Bob parle')->getTopics());
        self::assertSame([], $this->chat()->recent($cid, $allianceChannel));
        self::assertCount(1, $this->chat()->recent($ana, $allianceChannel));
    }

    public function testAnIgnoredPlayerCannotAddYouToAGroup(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        $group = self::getContainer()->get(ChatGroups::class)->create($bob, 'Club');
        $this->groupIds[] = $group->id;
        $this->ignored()->ignore($ana, 'Bob');

        $this->expectExceptionObject(new ChatRefused('Ana n’accepte pas vos invitations.'));
        self::getContainer()->get(ChatGroups::class)->add($bob, $group->id, 'Ana');
    }

    private function lastUpdateContaining(string $text): Update
    {
        $updates = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), $text)));
        self::assertNotEmpty($updates);

        return $updates[\count($updates) - 1];
    }

    /** @return list<Empire> tous en ligne */
    private function empires(string ...$names): array
    {
        $empires = array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
        foreach ($empires as $empire) {
            self::getContainer()->get(ChatPresence::class)->heartbeat($empire);
        }

        return $empires;
    }

    private function ignored(): IgnoredPlayers
    {
        return self::getContainer()->get(IgnoredPlayers::class);
    }

    private function chat(): Chat
    {
        return self::getContainer()->get(Chat::class);
    }
}
