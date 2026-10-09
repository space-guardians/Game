<?php

declare(strict_types=1);

namespace App\Tests\Integration\Model\Combat;

use App\Entity\Fleet;
use App\Entity\SpaceLocation;
use App\Factory\EmpireFactory;
use App\Model\Combat\SideMotion;
use App\Model\Fleet\SpacePosition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Mouvement d'un camp d'après l'état de ses flottes (§4.6.2) : à l'arrêt dès qu'une flotte tient sa position.
 */
final class SideMotionTest extends KernelTestCase
{
    use Factories;

    public function testSideHoldsItsPositionAsSoonAsOneFleetIsStopped(): void
    {
        self::bootKernel();
        $empire = EmpireFactory::createOne();
        $stationed = new Fleet($empire, 'Garde', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-09'));
        $inFlight = new Fleet($empire, 'Raid', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-09'));
        $inFlight->depart();
        $stranded = new Fleet($empire, 'Épave', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-09'));
        $stranded->strand(SpaceLocation::of(SpacePosition::planet($empire->getHomePlanet())));

        self::assertTrue(SideMotion::ofFleets([$stationed])->stationary);
        // Une flotte en panne, immobile, fait face elle aussi
        self::assertTrue(SideMotion::ofFleets([$stranded])->stationary);
        self::assertFalse(SideMotion::ofFleets([$inFlight])->stationary);
        self::assertTrue(SideMotion::ofFleets([$inFlight, $stationed])->stationary);
    }
}
