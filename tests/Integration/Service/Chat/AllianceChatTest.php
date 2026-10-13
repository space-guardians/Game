<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Entity\Empire;
use App\Exception\Chat\ChatRefused;
use App\Factory\EmpireFactory;
use App\Model\Chat\ChatTopics;
use App\Repository\AllianceMemberRepository;
use App\Service\Alliance\Alliances;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Canal d'alliance du chat (§4.10, §4.12.1) : réservé aux membres actuels, relayé sur le topic personnel de chacun ; un
 * joueur qui part ou est exclu cesse aussitôt de recevoir et d'écrire.
 */
final class AllianceChatTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ?string $channel = null;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-13 10:00:00');
    }

    protected function tearDown(): void
    {
        if (null !== $this->channel) {
            self::getContainer()->get(ChatBuffer::class)->clear($this->channel);
        }
        parent::tearDown();
    }

    public function testMembersTalkTogetherAndOutsidersAreKeptOut(): void
    {
        [$ana, $bob, $eve] = $this->alliance();
        $channel = (string) $this->chat()->allianceChannel($ana);
        self::assertSame($channel, $this->chat()->allianceChannel($bob));
        self::assertNull($this->chat()->allianceChannel($eve));

        $this->chat()->post($bob, $channel, 'Rassemblement ce soir');

        self::assertEqualsCanonicalizing([ChatTopics::user($ana->getUser()), ChatTopics::user($bob->getUser())], $this->topicsOf('Rassemblement ce soir'));
        self::assertCount(1, $this->chat()->recent($ana, $channel));
        foreach ([fn() => $this->chat()->post($eve, $channel, 'Je peux venir ?'), fn() => $this->chat()->recent($eve, $channel)] as $outsider) {
            try {
                $outsider();
                self::fail('Un joueur hors de l’alliance accède à son canal.');
            } catch (ChatRefused $refusal) {
                self::assertSame('Vous ne faites pas partie de cette alliance.', $refusal->getMessage());
            }
        }
    }

    public function testAPlayerWhoLeavesStopsReceivingAtOnce(): void
    {
        [$ana, $bob] = $this->alliance();
        $channel = (string) $this->chat()->allianceChannel($ana);

        self::getContainer()->get(Alliances::class)->leave($bob);
        $this->chat()->post($ana, $channel, 'Bob est parti');

        self::assertSame([ChatTopics::user($ana->getUser())], $this->topicsOf('Bob est parti'));
        $this->expectExceptionObject(new ChatRefused('Vous ne faites pas partie de cette alliance.'));
        $this->chat()->post($bob, $channel, 'Attendez !');
    }

    public function testAnExpelledPlayerStopsReceivingAtOnce(): void
    {
        [$ana, $bob] = $this->alliance();
        $channel = (string) $this->chat()->allianceChannel($ana);
        $member = self::getContainer()->get(AllianceMemberRepository::class)->findOneByEmpire($bob);
        \assert(null !== $member);

        self::getContainer()->get(Alliances::class)->expel($ana, $member);
        $this->chat()->post($ana, $channel, 'Bob est exclu');

        self::assertSame([ChatTopics::user($ana->getUser())], $this->topicsOf('Bob est exclu'));
    }

    /**
     * Alliance « Ligue » : Ana (fondatrice) et Bob ; Eve hors alliance.
     *
     * @return list<Empire>
     */
    private function alliance(): array
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $eve = EmpireFactory::createOne(['name' => 'Eve']);
        $alliances = self::getContainer()->get(Alliances::class);
        $alliances->found($ana, 'Ligue', 'LGS');
        $alliances->accept($alliances->invite($ana, 'Bob'), $bob);
        $this->channel = $this->chat()->allianceChannel($ana);

        return [$ana, $bob, $eve];
    }

    /** @return list<string> topics de la dernière publication contenant le texte */
    private function topicsOf(string $text): array
    {
        $updates = array_values(array_filter(self::getContainer()->get(PublishedUpdates::class)->all(), static fn($update): bool => str_contains($update->getData(), $text)));
        self::assertNotEmpty($updates);

        return $updates[\count($updates) - 1]->getTopics();
    }

    private function chat(): Chat
    {
        return self::getContainer()->get(Chat::class);
    }
}
