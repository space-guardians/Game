<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\AdminUser;
use App\Entity\GalaxyGeneration;
use App\Enum\Admin\AuditAction;
use App\Enum\Universe\GenerationStatus;
use App\Factory\AdminUserFactory;
use App\Factory\GalaxyFactory;
use App\Message\GenerateGalaxy;
use App\MessageHandler\GenerateGalaxyHandler;
use App\Repository\AdminAuditLogRepository;
use App\Repository\GalaxyGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Génération en arrière-plan d'une galaxie demandée depuis le panneau (§5.6.1).
 */
final class GenerateGalaxyHandlerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-05 10:00:00');
    }

    public function testGeneratesGalaxyAndJournalsAction(): void
    {
        $admin = AdminUserFactory::createOne(['email' => 'designer@space-guardians.local']);
        $id = $this->request($admin, 'Bras d’Orion', 40, 123);

        $this->handle($id);

        $generation = $this->reload($id);
        self::assertSame(GenerationStatus::Completed, $generation->getStatus());
        $galaxy = $generation->getGalaxy();
        self::assertNotNull($galaxy);
        self::assertSame('Bras d’Orion', $galaxy->getName());
        self::assertCount(40, $galaxy->getSystems());
        self::assertGreaterThan(0, $generation->getPlanetCount());
        self::assertEquals(new \DateTimeImmutable('2026-10-05 10:00:00'), $generation->getStartedAt());

        $entries = self::getContainer()->get(AdminAuditLogRepository::class)->findBySubject('Galaxy', (string) $galaxy->getId());
        self::assertCount(1, $entries);
        self::assertSame(AuditAction::Generate, $entries[0]->getAction());
        self::assertSame('designer@space-guardians.local', $entries[0]->getActorEmail());
        self::assertSame([null, 123], $entries[0]->getChanges()['seed']);
    }

    public function testSameRequestProducesSameGalaxy(): void
    {
        $first = $this->request(null, null, 30, 7);
        $second = $this->request(null, null, 30, 7);

        $this->handle($first);
        $this->handle($second);

        self::assertSame($this->positions($first), $this->positions($second));
        self::assertSame('Galaxie 1', $this->reload($first)->getGalaxy()?->getName());
        self::assertSame('Galaxie 2', $this->reload($second)->getGalaxy()?->getName());
    }

    public function testRecordsFailureWithoutRetrying(): void
    {
        $id = $this->request(null, null, 0, 1);

        $this->handle($id);

        $generation = $this->reload($id);
        self::assertSame(GenerationStatus::Failed, $generation->getStatus());
        self::assertSame('Il faut placer au moins un système.', $generation->getError());
        self::assertSame(0, GalaxyFactory::repository()->count([]));
    }

    public function testRedeliveredRunningGenerationIsMarkedInterrupted(): void
    {
        $id = $this->request(null, null, 20, 1);
        // Le worker a démarré la génération puis s'est arrêté (mémoire épuisée, redémarrage) : le message revient
        $this->reload($id)->start(new \DateTimeImmutable('2026-10-05 09:59:30'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->handle($id);

        $generation = $this->reload($id);
        self::assertSame(GenerationStatus::Failed, $generation->getStatus());
        self::assertStringContainsString('interrompue', (string) $generation->getError());
        self::assertSame(0, GalaxyFactory::repository()->count([]));
    }

    public function testIgnoresRequestAlreadyHandled(): void
    {
        $id = $this->request(null, null, 20, 1);
        $this->handle($id);

        $this->handle($id);

        self::assertSame(1, GalaxyFactory::repository()->count([]));
    }

    private function request(?AdminUser $admin, ?string $name, int $systems, int $seed): int
    {
        $generation = new GalaxyGeneration($admin, new \DateTimeImmutable('2026-10-05 09:59:00'));
        $generation->setName($name);
        $generation->setSystemCount($systems);
        $generation->setSeed($seed);
        $generation->lock(999);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($generation);
        $entityManager->flush();

        return (int) $generation->getId();
    }

    private function handle(int $id): void
    {
        self::getContainer()->get(GenerateGalaxyHandler::class)(new GenerateGalaxy($id));
    }

    private function reload(int $id): GalaxyGeneration
    {
        $generation = self::getContainer()->get(GalaxyGenerationRepository::class)->find($id);
        \assert($generation instanceof GalaxyGeneration);

        return $generation;
    }

    /** @return list<array{float, float}> */
    private function positions(int $id): array
    {
        $galaxy = $this->reload($id)->getGalaxy();
        \assert(null !== $galaxy);
        $positions = [];
        foreach ($galaxy->getSystems() as $system) {
            $positions[] = [$system->getPosition()->x, $system->getPosition()->y];
        }

        return $positions;
    }
}
