<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRecipientOffline;
use App\Exception\Chat\ChatRefused;
use App\Factory\EmpireFactory;
use App\Model\Chat\ChatGroup;
use App\Model\Chat\ChatTopics;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Chat\ChatGroups;
use App\Service\Chat\ChatPresence;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mercure\Update;
use Zenstruck\Foundry\Test\Factories;

/**
 * Groupes de chat (§4.12.1, §5.7) : composition éphémère dans Redis, gérée par le créateur, messages relayés sur le
 * topic personnel de chaque participant.
 */
final class ChatGroupsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    /** @var list<string> */
    private array $groupIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
        self::getContainer()->get(ChatPresence::class)->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatPresence::class)->clear();
        foreach ($this->groupIds as $id) {
            $this->chatGroups()->delete($id);
            self::getContainer()->get(ChatBuffer::class)->clear(Chat::GROUP_PREFIX . $id);
        }
        parent::tearDown();
    }

    public function testCreatorBuildsAGroupWhoseMessagesReachEveryParticipant(): void
    {
        [$ana, $bob, $cid, $eve] = $this->empires('Ana', 'Bob', 'Cid', 'Eve');
        $group = $this->create($ana, 'Escadre');
        $this->chatGroups()->add($ana, $group->id, 'Bob');
        $group = $this->chatGroups()->add($ana, $group->id, 'cid');

        self::assertSame('Escadre', $group->name);
        self::assertSame([(int) $ana->getId() => 'Ana', (int) $bob->getId() => 'Bob', (int) $cid->getId() => 'Cid'], $group->members);
        self::assertSame([$group->id], array_map(static fn($mine): string => $mine->id, $this->chatGroups()->of($bob)));
        self::assertSame([], $this->chatGroups()->of($eve));

        $message = $this->chat()->post($bob, $group->channel(), 'On attaque ?');

        $update = $this->lastUpdateContaining('On attaque ?');
        self::assertEqualsCanonicalizing(array_map(static fn(Empire $empire): string => ChatTopics::user($empire->getUser()), [$ana, $bob, $cid]), $update->getTopics());
        self::assertCount(1, $this->chat()->recent($cid, $group->channel()));
        self::assertSame($message->id, $this->chat()->recent($ana, $group->channel())[0]->id);
        foreach ([fn() => $this->chat()->post($eve, $group->channel(), 'Moi aussi'), fn() => $this->chat()->recent($eve, $group->channel())] as $outsider) {
            try {
                $outsider();
                self::fail('Un non-participant accède au groupe.');
            } catch (ChatRefused $refusal) {
                self::assertSame('Ce groupe n’existe plus ou vous n’en faites pas partie.', $refusal->getMessage());
            }
        }
    }

    public function testOnlyTheCreatorManagesAndOnlyOnlinePlayersJoin(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');
        EmpireFactory::createOne(['name' => 'Absent']);
        $group = $this->create($ana, 'Escadre');
        $this->chatGroups()->add($ana, $group->id, 'Bob');

        foreach ([
            [fn() => $this->chatGroups()->add($bob, $group->id, 'Ana'), 'Seul le créateur du groupe gère ses participants.'],
            [fn() => $this->chatGroups()->add($ana, $group->id, 'Bob'), 'Bob participe déjà au groupe.'],
            [fn() => $this->chatGroups()->remove($ana, $group->id, (int) $ana->getId()), 'Pour sortir du groupe, quittez-le.'],
            [fn() => $this->chatGroups()->create($ana, '  '), 'Le nom du groupe compte de 1 à 40 caractères.'],
        ] as [$attempt, $error]) {
            try {
                $attempt();
                self::fail('Accepté à tort : ' . $error);
            } catch (ChatRefused $refusal) {
                self::assertSame($error, $refusal->getMessage());
            }
        }
        $this->expectException(ChatRecipientOffline::class);
        $this->chatGroups()->add($ana, $group->id, 'Absent');
    }

    public function testRemovalDepartureAndTheLastOneOut(): void
    {
        [$ana, $bob, $cid] = $this->empires('Ana', 'Bob', 'Cid');
        $group = $this->create($ana, 'Escadre');
        $this->chatGroups()->add($ana, $group->id, 'Bob');
        $this->chatGroups()->add($ana, $group->id, 'Cid');

        // Retiré par le créateur : prévenu sur son topic, l'onglet se ferme
        $group = $this->chatGroups()->remove($ana, $group->id, (int) $cid->getId());
        self::assertFalse($group->has((int) $cid->getId()));
        self::assertSame([ChatTopics::user($cid->getUser())], $this->lastUpdateContaining('group_left')->getTopics());

        // Le créateur part : Bob prend la main ; le dernier qui part efface le groupe
        $group = $this->chatGroups()->leave($ana, $group->id);
        self::assertSame((int) $bob->getId(), $group?->ownerId);
        self::assertNull($this->chatGroups()->leave($bob, $group->id));
        self::assertNull($this->chatGroups()->get($group->id));
        self::assertSame([], $this->chatGroups()->of($bob));
    }

    public function testGroupExpiresAfterInactivity(): void
    {
        [$ana] = $this->empires('Ana');
        $group = $this->create($ana, 'Escadre');

        $ttl = self::getContainer()->get('app.redis')->ttl('test:chat:group:' . $group->id);

        self::assertGreaterThan(ChatGroups::INACTIVITY - 5, $ttl);
        self::assertLessThanOrEqual(ChatGroups::INACTIVITY, $ttl);
    }

    private function create(Empire $owner, string $name): ChatGroup
    {
        $group = $this->chatGroups()->create($owner, $name);
        $this->groupIds[] = $group->id;

        return $group;
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

    private function chatGroups(): ChatGroups
    {
        return self::getContainer()->get(ChatGroups::class);
    }

    private function chat(): Chat
    {
        return self::getContainer()->get(Chat::class);
    }
}
