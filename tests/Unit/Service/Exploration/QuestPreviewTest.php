<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Entity\ShipType;
use App\Enum\Exploration\QuestResolution;
use App\Enum\Exploration\QuestStatus;
use App\Model\Economy\Resources;
use App\Service\Exploration\QuestPreview;
use App\Service\Exploration\QuestRules;
use PHPUnit\Framework\TestCase;

final class QuestPreviewTest extends TestCase
{
    private const array CARGO = ['small_cargo' => 5000, 'light_fighter' => 50];

    private QuestPreview $preview;

    protected function setUp(): void
    {
        $this->preview = new QuestPreview(new QuestRules());
    }

    public function testAutomaticQuestShowsDrawChancesAndEffects(): void
    {
        $template = new QuestTemplate();
        $template->addOutcome($this->outcome('Butin', weight: 3, metal: 2000));
        $template->addOutcome($this->outcome('Mines', weight: 1, loss: 50));

        $simulation = $this->preview->simulate($template, [], ['small_cargo' => 1, 'light_fighter' => 4], new Resources(), self::CARGO);

        self::assertTrue($simulation->triggers());
        [$loot, $mines] = $simulation->outcomes;
        self::assertSame(0.75, $loot->probability);
        self::assertEquals(new Resources(2000, 0, 0), $loot->cargo);
        self::assertSame([], $loot->losses);
        self::assertSame(0.25, $mines->probability);
        self::assertSame(['light_fighter' => 2], $mines->losses);
        self::assertFalse($mines->fleetLost);
    }

    public function testChoiceQuestHasNoChanceAndChecksTheNextQuestAfterEffects(): void
    {
        $next = new QuestTemplate();
        $next->setName('Suite');
        $next->setStatus(QuestStatus::Published);
        $next->setRequiredCargoDeuterium(1000);
        $template = new QuestTemplate();
        $template->setResolution(QuestResolution::PlayerChoice);
        $give = $this->outcome('Donner', deuterium: -800);
        $give->setNextQuest($next);
        $keep = $this->outcome('Garder');
        $keep->setNextQuest($next);
        $template->addOutcome($give);
        $template->addOutcome($keep);

        $simulation = $this->preview->simulate($template, [], ['small_cargo' => 1], new Resources(0, 0, 1500), self::CARGO);

        self::assertNull($simulation->outcomes[0]->probability);
        // Après avoir donné 800, il ne reste que 700 : la suite ne se déclencherait pas
        self::assertFalse($simulation->outcomes[0]->chains());
        self::assertSame(['en cargaison : 1000 de deutérium'], $simulation->outcomes[0]->nextUnmet);
        self::assertTrue($simulation->outcomes[1]->chains());
    }

    public function testUnpublishedNextQuestOrLostFleetBreaksTheChain(): void
    {
        $draft = new QuestTemplate();
        $template = new QuestTemplate();
        $wipeout = $this->outcome('Trou noir', loss: 100);
        $wipeout->setNextQuest($draft);
        $template->addOutcome($wipeout);

        $result = $this->preview->simulate($template, [], ['light_fighter' => 3], new Resources(), self::CARGO)->outcomes[0];

        self::assertTrue($result->fleetLost);
        self::assertFalse($result->chains());
    }

    public function testUnmetTriggerConditionsAreListed(): void
    {
        $template = new QuestTemplate();
        $template->setRequiredShipType(new ShipType('recycler', 'Recycleur'));
        $template->addOutcome($this->outcome('Rien'));

        self::assertSame(['1 × Recycleur dans la flotte'], $this->preview->simulate($template, [], ['small_cargo' => 1], new Resources(), self::CARGO)->unmet);
    }

    public function testMinimalFleetMeetsTheConditions(): void
    {
        $template = new QuestTemplate();
        $template->setRequiredShipType(new ShipType('recycler', 'Recycleur'));
        $template->setRequiredShipCount(2);
        $template->setRequiredCargoCrystal(300);

        $fleet = $this->preview->minimalFleet($template, 'small_cargo');

        self::assertSame(['recycler' => 2], $fleet['ships']);
        self::assertEquals(new Resources(0, 300, 0), $fleet['cargo']);
        self::assertSame(['small_cargo' => 1], $this->preview->minimalFleet(new QuestTemplate(), 'small_cargo')['ships']);
    }

    private function outcome(string $label, int $weight = 1, int $metal = 0, int $deuterium = 0, int $loss = 0): QuestOutcome
    {
        $outcome = new QuestOutcome();
        $outcome->setLabel($label);
        $outcome->setWeight($weight);
        $outcome->setMetal($metal);
        $outcome->setDeuterium($deuterium);
        $outcome->setShipLossPercent($loss);

        return $outcome;
    }
}
