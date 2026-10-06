<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Entity\Technology;
use App\Factory\EmpireFactory;
use App\Model\Economy\Resources;
use App\Repository\BuildingTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Research\ResearchQueue;
use App\Service\Scheduling\ScheduledEventResolver;
use App\Tests\Fixtures\Mercure\PublishedUpdates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Fin de recherche poussée en Turbo Stream sur le topic privé de l'empire (§4.4, §5.2).
 */
final class ResearchCompletionNotifierTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    public function testPushesNotificationAndRefreshToEmpire(): void
    {
        self::bootKernel();
        $clock = self::mockTime('2026-10-06 10:00:00');
        $empire = EmpireFactory::createOne(['foundedAt' => $clock->now()]);
        $planet = $empire->getHomePlanet();
        $laboratory = self::getContainer()->get(BuildingTypeRepository::class)->findOneByCode('research_lab');
        \assert(null !== $laboratory);
        $planet->setBuildingLevel($laboratory, 1);
        $planet->storeResources(new Resources(500, 900, 500), $clock->now());
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $energy = self::getContainer()->get(TechnologyRepository::class)->findOneByCode('energy');
        \assert($energy instanceof Technology);

        $item = self::getContainer()->get(ResearchQueue::class)->start($planet, $energy);
        $clock->sleep(1440);
        self::getContainer()->get(ScheduledEventResolver::class)->resolve((int) $item->getEvent()?->getId());

        $updates = self::getContainer()->get(PublishedUpdates::class)->all();
        self::assertCount(1, $updates);
        self::assertSame(['/empire/' . $empire->getId()], $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        self::assertStringContainsString('Recherche terminée : Énergie niveau 1.', $updates[0]->getData());
        self::assertStringContainsString('<turbo-stream action="refresh"></turbo-stream>', $updates[0]->getData());
    }
}
