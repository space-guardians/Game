<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\ShipType;
use App\Enum\Combat\CombatSide;
use App\Enum\Fleet\FormationRow;
use App\Factory\EmpireFactory;
use App\Model\Combat\CombatGroup;
use App\Repository\ClassMatchupRepository;
use App\Repository\ShipTypeRepository;
use App\Repository\TechnologyRepository;
use App\Service\Combat\CombatEngine;
use App\Service\Combat\CombatGroups;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Groupes de combat tirés de vraies flottes (§4.7) : formation, technologies, fusion de plusieurs flottes d'un camp.
 */
final class CombatGroupsTest extends KernelTestCase
{
    use Factories;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testFleetsBecomeGroupsWithTheirFormationAndTechnologies(): void
    {
        $empire = EmpireFactory::createOne();
        $this->research($empire, 'weapons', 2);
        $this->research($empire, 'armour', 5);
        $fleet = $this->fleet($empire, ['cruiser' => 3, 'small_cargo' => 1]);

        $groups = self::getContainer()->get(CombatGroups::class)->of(CombatSide::Attacker, [$fleet]);

        $byType = [];
        foreach ($groups as $group) {
            $byType[$group->typeCode] = $group;
        }
        // Formation par défaut : militaires devant, civils derrière
        self::assertSame(FormationRow::Front, $byType['cruiser']->row);
        self::assertSame(FormationRow::Back, $byType['small_cargo']->row);
        self::assertSame(3, $byType['cruiser']->count);
        self::assertSame('cruiser', $byType['cruiser']->classCode);
        self::assertNull($byType['small_cargo']->classCode);
        // Croiseur : attaque 400 × 1,2 ; coque 27 000 × 1,5 ; bouclier sans bonus
        self::assertEqualsWithDelta(480.0, $byType['cruiser']->attack, 1e-6);
        self::assertEqualsWithDelta(40_500.0, $byType['cruiser']->hull, 1e-6);
        self::assertEqualsWithDelta(50.0, $byType['cruiser']->shield, 1e-6);
        self::assertSame($fleet->getId(), $byType['cruiser']->fleetId);
    }

    public function testSeveralFleetsOfASideAreMergedAndLossesAttributed(): void
    {
        $empire = EmpireFactory::createOne();
        $first = $this->fleet($empire, ['light_fighter' => 10]);
        $second = $this->fleet($empire, ['light_fighter' => 10]);
        $enemy = $this->fleet(EmpireFactory::createOne(), ['battleship' => 20]);
        $groups = self::getContainer()->get(CombatGroups::class);

        $all = [...$groups->of(CombatSide::Defender, [$first, $second]), ...$groups->of(CombatSide::Attacker, [$enemy])];
        // Même case pour les deux flottes, mais un groupe chacune
        self::assertCount(2, array_filter($all, static fn(CombatGroup $group): bool => CombatSide::Defender === $group->side));

        $result = self::getContainer()->get(CombatEngine::class)->resolve($all, self::getContainer()->get(ClassMatchupRepository::class)->matrix(), self::getContainer()->get(Randomizer::class));

        self::assertSame(CombatSide::Attacker, $result->winner);
        self::assertSame(['light_fighter' => 10], $result->lossesOfFleet((int) $first->getId()));
        self::assertSame(['light_fighter' => 10], $result->lossesOfFleet((int) $second->getId()));
    }

    /** @param array<string, int> $ships */
    private function fleet(Empire $empire, array $ships): Fleet
    {
        $fleet = new Fleet($empire, 'Escadre', $empire->getHomePlanet(), new \DateTimeImmutable('2026-10-09'));
        foreach ($ships as $code => $quantity) {
            $type = self::getContainer()->get(ShipTypeRepository::class)->findOneByCode($code);
            \assert($type instanceof ShipType);
            $fleet->addShips($type, $quantity);
        }
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($fleet);
        $entityManager->flush();

        return $fleet;
    }

    private function research(Empire $empire, string $code, int $level): void
    {
        $technology = self::getContainer()->get(TechnologyRepository::class)->findOneByCode($code);
        \assert(null !== $technology);
        $empire->setResearchLevel($technology, $level);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
