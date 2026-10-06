<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Entity\BuildingType;
use App\Factory\EmpireFactory;
use App\Repository\BuildingTypeRepository;
use App\Service\Economy\BuildingConstruction;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Premier flux Mercure (§5.2) : fin de construction poussée en Turbo Stream sur le topic privé de l'empire.
 */
final class BuildingCompletionNotifierTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-06 10:00:00');
    }

    public function testPushesNotificationAndRefreshToEmpire(): void
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $item = self::getContainer()->get(BuildingConstruction::class)->start($empire->getHomePlanet(), $this->type('metal_mine'));
        $this->clock->sleep(120);

        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $item->getEvent()?->getId());

        $updates = self::getContainer()->get(PublishedUpdates::class)->all();
        self::assertCount(1, $updates);
        self::assertSame(['/empire/' . $empire->getId()], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        $stream = $updates[0]->getData();
        self::assertStringContainsString('<turbo-stream action="append" target="sg-notifications">', $stream);
        self::assertStringContainsString('Construction terminée : Mine de métal niveau 1 sur la planète mère', $stream);
        self::assertStringContainsString((string) $empire->getHomePlanet(), $stream);
        self::assertStringContainsString('<turbo-stream action="refresh"></turbo-stream>', $stream);
    }

    public function testCancelledConstructionIsNotAnnounced(): void
    {
        $empire = EmpireFactory::createOne(['foundedAt' => $this->clock->now()]);
        $construction = self::getContainer()->get(BuildingConstruction::class);
        $item = $construction->start($empire->getHomePlanet(), $this->type('metal_mine'));
        $construction->cancel($empire->getHomePlanet());
        $this->clock->sleep(120);

        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $item->getEvent()?->getId());

        self::assertSame([], self::getContainer()->get(PublishedUpdates::class)->all());
    }

    private function type(string $code): BuildingType
    {
        $type = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode($code);
        \assert(null !== $type);

        return $type;
    }
}
