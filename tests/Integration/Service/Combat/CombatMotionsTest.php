<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\GlobalPosition;
use App\Entity\ShipType;
use App\Enum\Combat\AttackAngle;
use App\Enum\Fleet\FleetAction;
use App\Factory\EmpireFactory;
use App\Factory\StarSystemFactory;
use App\Model\Economy\Resources;
use App\Model\Fleet\MissionStep;
use App\Model\Fleet\SpacePosition;
use App\Repository\ShipTypeRepository;
use App\Service\Combat\CombatMotions;
use App\Service\Combat\EngagementRules;
use App\Service\Fleet\FleetDispatch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Caps des flottes en vol et angle d'attaque entre flottes convergentes (§4.7).
 */
final class CombatMotionsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-10 10:00:00');
    }

    public function testHeadingOfAFleetInFlightIsItsLastSegment(): void
    {
        $empire = $this->empire();
        $home = $empire->getHomePlanet()->getSystem()->getPosition();
        $fleet = $this->fleet($empire);
        $this->send($fleet, $home->x + 3000, $home->y);

        [$dx, $dy] = $this->motions()->heading($fleet) ?? [0.0, 0.0];

        // Plein est
        self::assertGreaterThan(0, $dx);
        self::assertEqualsWithDelta(0.0, $dy, 1e-6);
        self::assertFalse($this->motions()->of([$fleet])->stationary);
        self::assertNull($this->motions()->heading($this->fleet($empire)));
        self::assertTrue($this->motions()->of([$this->fleet($empire)])->stationary);
    }

    public function testFleetCatchingUpWithAnotherHitsItFromBehind(): void
    {
        $empire = $this->empire();
        $home = $empire->getHomePlanet()->getSystem()->getPosition();
        $chaser = $this->fleet($empire);
        $chased = $this->fleet($empire);
        // Les deux filent vers l'est, vers le même point
        $this->send($chaser, $home->x + 3000, $home->y);
        $this->send($chased, $home->x + 3000, $home->y);
        $rules = new EngagementRules();

        $attacker = $this->motions()->of([$chaser]);
        $defender = $this->motions()->of([$chased]);

        self::assertSame(AttackAngle::Rear, $rules->angle($defender, $attacker));
    }

    private function send(Fleet $fleet, float $x, float $y): void
    {
        $home = $fleet->getEmpire()->getHomePlanet()->getSystem();
        $target = StarSystemFactory::createOne(['galaxy' => $home->getGalaxy(), 'position' => new GlobalPosition($x, $y)]);
        self::getContainer()->get(FleetDispatch::class)->dispatch($fleet, [new MissionStep(SpacePosition::system($target), FleetAction::Station, 'cible')], 100, new Resources(), $fleet->tankCapacity());
    }

    private function empire(): Empire
    {
        $empire = EmpireFactory::createOne();
        $empire->getHomePlanet()->storeResources(new Resources(0, 0, 50_000), new \DateTimeImmutable('2026-10-10 10:00:00'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        return $empire;
    }

    private function fleet(Empire $empire): Fleet
    {
        $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode('light_fighter');
        \assert($type instanceof ShipType);
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-10'));
        $fleet->addShips($type, 5);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function motions(): CombatMotions
    {
        return self::getContainer()->get(CombatMotions::class);
    }
}
