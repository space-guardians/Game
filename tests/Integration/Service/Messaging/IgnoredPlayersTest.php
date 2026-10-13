<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Messaging;

use App\Entity\Empire;
use App\Exception\Messaging\InvalidMessage;
use App\Factory\EmpireFactory;
use App\Service\Messaging\IgnoredPlayers;
use App\Service\Messaging\Messaging;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Joueurs ignorés en messagerie (§4.12.4) : la liste elle-même, messages bloqués, ajout à une conversation refusé.
 */
final class IgnoredPlayersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
    }

    public function testTheListRefusesUnknownSelfAndDuplicates(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob');

        self::assertSame($bob, $this->ignored()->ignore($ana, ' bob '));
        self::assertSame([(int) $bob->getId()], $this->ignored()->idsOf($ana));
        self::assertTrue($this->ignored()->blocks($ana, $bob));
        self::assertFalse($this->ignored()->blocks($bob, $ana));

        foreach (['Bob' => 'Vous ignorez déjà Bob.', 'Ana' => 'Vous ne pouvez pas vous ignorer vous-même.', 'Zed' => 'Aucun joueur ne s’appelle « Zed ».'] as $name => $expected) {
            try {
                $this->ignored()->ignore($ana, $name);
                self::fail('Joueur ignoré à tort.');
            } catch (InvalidMessage $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }

        self::assertSame($bob, $this->ignored()->unignore($ana, (int) $bob->getId()));
        self::assertSame([], $this->ignored()->idsOf($ana));
        $this->expectExceptionObject(new InvalidMessage('Vous n’ignorez pas ce joueur.'));
        $this->ignored()->unignore($ana, (int) $bob->getId());
    }

    public function testAnIgnoredPlayerCanNoLongerWriteNorAdd(): void
    {
        [$ana, $bob] = $this->empires('Ana', 'Bob', 'Cid', 'Dan');
        $direct = $this->messaging()->start($bob, ['Ana'], null, 'Bonjour Ana');
        $group = $this->messaging()->start($bob, ['Cid', 'Dan'], null, 'Bonjour à tous');
        $this->ignored()->ignore($ana, 'Bob');

        foreach ([
            fn() => $this->messaging()->start($bob, ['Ana'], null, 'Tu m’ignores ?'),
            fn() => $this->messaging()->start($bob, ['Cid', 'Ana'], null, 'Réunion'),
            fn() => $this->messaging()->reply($direct, $bob, 'Réponds-moi'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Message envoyé à un joueur qui ignore l’auteur.');
            } catch (InvalidMessage $refusal) {
                self::assertSame('Ana n’accepte pas vos messages.', $refusal->getMessage());
            }
        }
        // Celui qui ignore peut encore écrire
        $this->messaging()->reply($direct, $ana, 'Laisse-moi tranquille');

        $this->expectExceptionObject(new InvalidMessage('Ana n’accepte pas d’être ajouté par vous.'));
        $this->messaging()->addParticipants($group, $bob, ['Ana']);
    }

    public function testInAGroupTheIgnorerNeitherReceivesNorSeesUnread(): void
    {
        [$ana, $bob, $cid] = $this->empires('Ana', 'Bob', 'Cid');
        $group = $this->messaging()->start($cid, ['Ana', 'Bob'], 'Escadre', 'Bienvenue');
        $this->messaging()->markRead($group->participantOf($ana) ?? throw new \LogicException());
        $this->ignored()->ignore($ana, 'Bob');
        $before = \count(self::getContainer()->get(PublishedUpdates::class)->all());

        $this->clock->sleep(60);
        $this->messaging()->reply($group, $bob, 'Message de Bob');

        $topics = array_map(static fn($update): string => $update->getTopics()[0], \array_slice(self::getContainer()->get(PublishedUpdates::class)->all(), $before));
        self::assertEqualsCanonicalizing(['/empire/' . $bob->getId(), '/empire/' . $cid->getId()], $topics);
        self::assertSame(0, $this->messaging()->unreadCount($ana));
        self::assertSame(1, $this->messaging()->unreadCount($cid));

        // Un message d'un autre participant reste reçu
        $this->clock->sleep(60);
        $this->messaging()->reply($group, $cid, 'Message de Cid');
        self::assertSame(1, $this->messaging()->unreadCount($ana));
    }

    /** @return list<Empire> */
    private function empires(string ...$names): array
    {
        return array_map(static fn(string $name): Empire => EmpireFactory::createOne(['name' => $name]), array_values($names));
    }

    private function ignored(): IgnoredPlayers
    {
        return self::getContainer()->get(IgnoredPlayers::class);
    }

    private function messaging(): Messaging
    {
        return self::getContainer()->get(Messaging::class);
    }
}
