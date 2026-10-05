<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Galaxy;
use App\Entity\GalaxyGeneration;
use App\Entity\GalaxyShapeTemplate;
use App\Enum\Universe\GenerationStatus;
use App\Model\Universe\SpiralGalaxyShape;
use PHPUnit\Framework\TestCase;

final class GalaxyGenerationTest extends TestCase
{
    public function testLockDrawsSeedOnlyWhenEmptyAndCopiesDefaultShape(): void
    {
        $random = $this->generation();
        $random->lock(123);
        $chosen = $this->generation();
        $chosen->setSeed(7);
        $chosen->lock(123);

        self::assertSame(123, $random->getSeed());
        self::assertSame(7, $chosen->getSeed());
        self::assertEquals(new SpiralGalaxyShape(), $random->getShape());
    }

    public function testLockFreezesTemplateShape(): void
    {
        $template = GalaxyShapeTemplate::fromShape('Six bras', new SpiralGalaxyShape(arms: 6));
        $generation = $this->generation();
        $generation->setShapeTemplate($template);

        $generation->lock(1);
        $template->setArms(2);

        self::assertSame(6, $generation->getShape()->arms);
    }

    public function testFollowsLifecycle(): void
    {
        $generation = $this->generation();
        $generation->lock(1);
        self::assertSame(GenerationStatus::Pending, $generation->getStatus());

        $generation->start(new \DateTimeImmutable('2026-10-05 10:00:00'));
        self::assertSame(GenerationStatus::Running, $generation->getStatus());
        self::assertFalse($generation->getStatus()->isFinished());

        $galaxy = new Galaxy(1, 'Orion');
        $generation->complete($galaxy, 4200, new \DateTimeImmutable('2026-10-05 10:00:09'));
        self::assertSame(GenerationStatus::Completed, $generation->getStatus());
        self::assertTrue($generation->getStatus()->isFinished());
        self::assertSame($galaxy, $generation->getGalaxy());
        self::assertSame(4200, $generation->getPlanetCount());
    }

    public function testCannotStartTwice(): void
    {
        $generation = $this->generation();
        $generation->start(new \DateTimeImmutable());

        $this->expectException(\LogicException::class);

        $generation->start(new \DateTimeImmutable());
    }

    public function testRecordsFailure(): void
    {
        $generation = $this->generation();
        $generation->start(new \DateTimeImmutable());

        $generation->fail('Il faut placer au moins un système.', new \DateTimeImmutable());

        self::assertSame(GenerationStatus::Failed, $generation->getStatus());
        self::assertSame('Il faut placer au moins un système.', $generation->getError());
    }

    public function testBlankNameMeansDefaultName(): void
    {
        $generation = $this->generation();
        $generation->setName('   ');

        self::assertNull($generation->getName());
    }

    private function generation(): GalaxyGeneration
    {
        return new GalaxyGeneration(null, new \DateTimeImmutable('2026-10-05 09:59:00'));
    }
}
