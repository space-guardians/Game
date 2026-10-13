<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Chat;

use App\Factory\EmpireFactory;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Chat\ChatPresence;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Présence des joueurs (§4.12.1, §5.7) : battement de cœur dans Redis, expiré après TTL secondes de silence.
 */
final class ChatPresenceTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-13 10:00:00');
        $this->presence()->clear();
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
    }

    protected function tearDown(): void
    {
        $this->presence()->clear();
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        parent::tearDown();
    }

    public function testHeartbeatKeepsAPlayerOnlineUntilItStops(): void
    {
        $ana = EmpireFactory::createOne();
        $bob = EmpireFactory::createOne();

        $this->presence()->heartbeat($ana);

        self::assertTrue($this->presence()->isOnline($ana));
        self::assertFalse($this->presence()->isOnline($bob));
        self::assertSame([(int) $ana->getId()], $this->presence()->onlineIds());
        self::assertSame([(int) $ana->getId() => true, (int) $bob->getId() => false], $this->presence()->statuses([(int) $ana->getId(), (int) $bob->getId()]));

        // Un battement toutes les 30 s suffit ; au-delà de TTL sans nouvelles, hors ligne
        $this->clock->sleep(ChatPresence::TTL - 1);
        self::assertTrue($this->presence()->isOnline($ana));
        $this->clock->sleep(1);
        self::assertFalse($this->presence()->isOnline($ana));
        self::assertSame([], $this->presence()->onlineIds());
    }

    public function testLeavingAndPostingUpdatePresence(): void
    {
        $ana = EmpireFactory::createOne();
        $this->presence()->heartbeat($ana);

        $this->presence()->leave($ana);
        self::assertFalse($this->presence()->isOnline($ana));

        // Écrire dans le chat, c'est être là
        self::getContainer()->get(Chat::class)->post($ana, Chat::PUBLIC_CHANNEL, 'Me revoilà');
        self::assertTrue($this->presence()->isOnline($ana));
    }

    private function presence(): ChatPresence
    {
        return self::getContainer()->get(ChatPresence::class);
    }
}
