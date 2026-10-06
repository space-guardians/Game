<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Fleet;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Entity\Technology;
use App\Enum\Fleet\SegmentKind;
use App\Factory\EmpireFactory;
use App\Factory\PlanetFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\EconomySettings;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Fleet\FleetTravel;
use App\Service\Fleet\FuelRules;
use App\Service\Fleet\TrajectoryPlanner;
use App\Service\Fleet\TravelRules;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Trajet d'une flotte (§4.6.1) : segments depuis sa planète, vitesse du plus lent avec les propulsions de l'empire.
 */
final class FleetTravelTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testSlowestShipWithItsEmpireDriveSetsTheSpeed(): void
    {
        $empire = EmpireFactory::createOne();
        $empire->setResearchLevel($this->technology('combustion_drive'), 2);
        $fleet = $this->fleet($empire, ['light_fighter' => 5, 'small_cargo' => 2]);

        // Transporteur léger : 5 000 × (1 + 0,1 × 2) ; chasseur léger : 12 500 × 1,2
        self::assertEqualsWithDelta(6000.0, $this->travel()->speed($fleet), 1e-9);
    }

    public function testPlansTripToAnotherSystem(): void
    {
        $empire = EmpireFactory::createOne();
        $fleet = $this->fleet($empire, ['light_fighter' => 1]);
        $home = $empire->getHomePlanet()->getSystem();
        // Système voisin de la même galaxie, à 2 000 du système d'origine
        $target = PlanetFactory::createOne(['system' => StarSystemFactory::new([
            'galaxy' => $home->getGalaxy(),
            'position' => new GlobalPosition($home->getPosition()->x + 2000, $home->getPosition()->y),
        ])]);

        $plan = $this->travel()->plan($fleet, SpacePosition::planet($target), 50);

        $kinds = array_map(static fn($segment): SegmentKind => $segment->kind, $plan->trajectory->segments);
        self::assertSame([SegmentKind::Exit, SegmentKind::Interstellar, SegmentKind::Approach], $kinds);
        self::assertSame(50, $plan->speedPercent);
        self::assertEqualsWithDelta(12500.0, $plan->speed, 1e-9);
        self::assertGreaterThan(10, $plan->durationSeconds);
        $departure = new \DateTimeImmutable('2026-10-06 10:00:00');
        self::assertEquals($departure->modify(\sprintf('+%d seconds', $plan->durationSeconds)), $plan->arrivalFrom($departure));
        $arrival = $plan->positionAt($departure, $plan->arrivalFrom($departure));
        self::assertSame(1.0, $arrival['progress']);
        self::assertEqualsWithDelta(SpacePosition::planet($target)->global()->x, $arrival['position']->x, 1e-6);
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable());
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }

        return $fleet;
    }

    private function technology(string $code): Technology
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        \assert(null !== $technology);

        return $technology;
    }

    /** Construit ici : aucun service ne l'utilise encore, le conteneur le retire (#33 l'utilisera) */
    private function travel(): FleetTravel
    {
        return new FleetTravel(new TrajectoryPlanner(), new TravelRules(), self::getContainer()->get(EconomySettings::class), new FuelRules());
    }
}
