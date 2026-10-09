<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Entity\ShipType;
use App\Entity\Technology;
use App\Model\Economy\Resources;
use App\Model\Exploration\ExplorationContext;
use App\Service\Exploration\QuestRules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class QuestRulesTest extends TestCase
{
    private QuestRules $rules;

    protected function setUp(): void
    {
        $this->rules = new QuestRules();
    }

    public function testQuestWithoutConditionsIsAlwaysEligible(): void
    {
        self::assertTrue($this->rules->isEligible(new QuestTemplate(), $this->context()));
    }

    public function testConditionsOnTechnologyShipsAndCargo(): void
    {
        $template = new QuestTemplate();
        $template->setRequiredTechnology(new Technology('astrophysics', 'Astrophysique'));
        $template->setRequiredTechnologyLevel(2);
        $template->setRequiredShipType(new ShipType('recycler', 'Recycleur'));
        $template->setRequiredShipCount(3);
        $template->setRequiredCargoDeuterium(1000);

        self::assertSame([
            'Astrophysique niveau 2',
            '3 × Recycleur dans la flotte',
            'en cargaison : 1000 de deutérium',
        ], $this->rules->unmetConditions($template, $this->context()));

        $equipped = $this->context(['astrophysics' => 2], ['recycler' => 3], new Resources(0, 0, 1000));
        self::assertTrue($this->rules->isEligible($template, $equipped));
        // Un niveau de moins suffit à bloquer
        self::assertSame(['Astrophysique niveau 2'], $this->rules->unmetConditions($template, $this->context(['astrophysics' => 1], ['recycler' => 3], new Resources(0, 0, 1000))));
    }

    public function testTechnologyOrShipWithoutMinimumMeansAtLeastOne(): void
    {
        $template = new QuestTemplate();
        $template->setRequiredShipType(new ShipType('recycler', 'Recycleur'));

        self::assertFalse($this->rules->isEligible($template, $this->context()));
        self::assertTrue($this->rules->isEligible($template, $this->context([], ['recycler' => 1])));
    }

    public function testDrawWalksCumulatedChances(): void
    {
        $common = $this->template(60);
        $rare = $this->template(30);

        $draws = ['common' => 0, 'rare' => 0, 'none' => 0];
        $randomizer = new Randomizer(new Mt19937(7));
        for ($i = 0; $i < 2000; ++$i) {
            $drawn = $this->rules->drawQuest([$common, $rare], $randomizer);
            ++$draws[$drawn === $common ? 'common' : ($drawn === $rare ? 'rare' : 'none')];
        }

        // 60 % / 30 % / 10 %, à l'aléa près
        self::assertEqualsWithDelta(1200, $draws['common'], 100);
        self::assertEqualsWithDelta(600, $draws['rare'], 80);
        self::assertEqualsWithDelta(200, $draws['none'], 60);
        self::assertNull($this->rules->drawQuest([], $randomizer));
        // 100 % : toujours ; au-delà de 100 % cumulés, les suivantes sont rognées
        $certain = $this->template(100);
        for ($i = 0; $i < 50; ++$i) {
            self::assertSame($certain, $this->rules->drawQuest([$certain, $rare], $randomizer));
        }
    }

    public function testOutcomeIsDrawnByWeight(): void
    {
        $template = new QuestTemplate();
        $often = $this->outcome(weight: 3);
        $seldom = $this->outcome(weight: 1);
        $never = $this->outcome(weight: 0);
        foreach ([$often, $seldom, $never] as $outcome) {
            $template->addOutcome($outcome);
        }

        $counts = [0, 0, 0];
        $randomizer = new Randomizer(new Mt19937(3));
        for ($i = 0; $i < 2000; ++$i) {
            ++$counts[array_search($this->rules->drawOutcome($template, $randomizer), [$often, $seldom, $never], true)];
        }

        self::assertEqualsWithDelta(1500, $counts[0], 80);
        self::assertEqualsWithDelta(500, $counts[1], 80);
        self::assertSame(0, $counts[2]);

        $silent = new QuestTemplate();
        $silent->addOutcome($this->outcome(weight: 0));
        self::assertNull($this->rules->drawOutcome($silent, $randomizer));
    }

    public function testShipLossesAreRoundedDownPerType(): void
    {
        self::assertSame(['light_fighter' => 1, 'cruiser' => 5], $this->rules->shipLosses(['light_fighter' => 19, 'cruiser' => 50, 'recycler' => 9], 10));
        self::assertSame([], $this->rules->shipLosses(['light_fighter' => 19], 0));
        self::assertSame(['light_fighter' => 19], $this->rules->shipLosses(['light_fighter' => 19], 100));
    }

    public function testCargoLossesNeverGoBelowZeroAndGainsFillFreeCapacity(): void
    {
        $outcome = $this->outcome(metal: 4000, crystal: 3000, deuterium: -800);

        // 10 000 de capacité, 2 000 à bord dont 500 de deutérium : -500 de deutérium, puis 8 500 libres
        $after = $this->rules->cargoAfter(new Resources(1500, 0, 500), 10_000, $outcome);

        self::assertEquals(new Resources(5500, 3000, 0), $after);
        // Soutes presque pleines : le gain est tronqué, métal d'abord
        self::assertEquals(new Resources(10_000, 0, 0), $this->rules->cargoAfter(new Resources(9000, 0, 0), 10_000, $this->outcome(metal: 4000, crystal: 3000)));
    }

    public function testCargoShrinksWithLostHolds(): void
    {
        // 1 000 à bord pour une capacité tombée à 500 : réduite proportionnellement
        self::assertEquals(new Resources(250, 250, 0), $this->rules->cargoAfter(new Resources(500, 500, 0), 500, $this->outcome()));
    }

    /**
     * @param array<string, int> $technologies
     * @param array<string, int> $ships
     */
    private function context(array $technologies = [], array $ships = [], ?Resources $cargo = null): ExplorationContext
    {
        return new ExplorationContext($technologies, $ships, $cargo ?? new Resources(), 10_000);
    }

    private function template(int $chance): QuestTemplate
    {
        $template = new QuestTemplate();
        $template->setChance($chance);

        return $template;
    }

    private function outcome(int $weight = 1, int $metal = 0, int $crystal = 0, int $deuterium = 0): QuestOutcome
    {
        $outcome = new QuestOutcome();
        $outcome->setWeight($weight);
        $outcome->setMetal($metal);
        $outcome->setCrystal($crystal);
        $outcome->setDeuterium($deuterium);

        return $outcome;
    }
}
