<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Galaxy;
use App\Entity\GameEvent;
use App\Entity\GlobalPosition;
use App\Entity\OrbitalPosition;
use App\Entity\Planet;
use App\Entity\StarSystem;
use App\Enum\Event\GameEventScope;
use App\Enum\Event\GameEventStatus;
use PHPUnit\Framework\TestCase;

/**
 * Événement de jeu (§4.16) : portée (univers, galaxie, zone), période et cycle.
 */
final class GameEventTest extends TestCase
{
    public function testScopeDecidesWhichPlanetsAreCovered(): void
    {
        $orion = new Galaxy(1, 'Orion');
        $near = $this->planetAt($orion, 100.0, 0.0);
        $far = $this->planetAt($orion, 900.0, 0.0);
        $elsewhere = $this->planetAt(new Galaxy(2, 'Véga'), 100.0, 0.0);
        $event = $this->event();

        self::assertTrue($event->covers($near) && $event->covers($far) && $event->covers($elsewhere));

        $event->setScope(GameEventScope::Galaxy);
        $event->setGalaxy($orion);
        self::assertSame([true, true, false], [$event->covers($near), $event->covers($far), $event->covers($elsewhere)]);

        $event->setScope(GameEventScope::Zone);
        $event->setZoneX(0.0);
        $event->setZoneY(0.0);
        $event->setZoneRadius(500.0);
        self::assertSame([true, false, false], [$event->covers($near), $event->covers($far), $event->covers($elsewhere)]);
    }

    public function testRunsOnlyBetweenItsStartAndEndOnceStarted(): void
    {
        $event = $this->event();
        $during = new \DateTimeImmutable('2026-10-15 12:00');

        self::assertFalse($event->isRunningAt($during));
        $event->start();
        self::assertTrue($event->isRunningAt($during));
        self::assertFalse($event->isRunningAt(new \DateTimeImmutable('2026-10-17 10:00')));

        $event->cancel();
        self::assertSame(GameEventStatus::Cancelled, $event->getStatus());
        $event->start();
        $event->finish();
        self::assertSame(GameEventStatus::Cancelled, $event->getStatus());
    }

    public function testEffectsAreSpelledOut(): void
    {
        $event = $this->event();
        $event->setResearchBonus(50);

        self::assertSame('production +20 %, recherche +50 %', $event->effectsLabel());
    }

    private function event(): GameEvent
    {
        $event = new GameEvent(new \DateTimeImmutable('2026-10-15 10:00'), new \DateTimeImmutable('2026-10-17 10:00'));
        $event->setName('Pluie de météores');
        $event->setProductionBonus(20);

        return $event;
    }

    private function planetAt(Galaxy $galaxy, float $x, float $y): Planet
    {
        return new Planet(new StarSystem($galaxy, 1, new GlobalPosition($x, $y)), new OrbitalPosition(4, 40.0, 0.0), 20);
    }
}
