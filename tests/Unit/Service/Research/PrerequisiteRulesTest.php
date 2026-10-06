<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research;

use App\Entity\BuildingType;
use App\Entity\Prerequisite;
use App\Entity\Technology;
use App\Enum\Economy\BuildingEffect;
use App\Service\Research\PrerequisiteRules;
use PHPUnit\Framework\TestCase;

final class PrerequisiteRulesTest extends TestCase
{
    public function testBuildingRequirementIsCheckedAgainstPlanetLevels(): void
    {
        $fusion = new BuildingType('fusion_reactor', 'Centrale à fusion', BuildingEffect::FusionEnergy);
        $synthesizer = Prerequisite::of($fusion, new BuildingType('deuterium_synthesizer', 'Synthétiseur', BuildingEffect::DeuteriumProduction), 5);
        $energy = Prerequisite::of($fusion, new Technology('energy', 'Énergie'), 3);

        $rules = new PrerequisiteRules();

        self::assertSame([$synthesizer, $energy], $rules->missing([$synthesizer, $energy], [], []));
        self::assertSame([$energy], $rules->missing([$synthesizer, $energy], ['deuterium_synthesizer' => 5], ['energy' => 2]));
        self::assertSame([], $rules->missing([$synthesizer, $energy], ['deuterium_synthesizer' => 6], ['energy' => 3]));
    }

    public function testSameCodeIsNotConfusedBetweenBuildingsAndTechnologies(): void
    {
        $target = new Technology('astrophysics', 'Astrophysique');
        $espionage = Prerequisite::of($target, new Technology('espionage', 'Espionnage'), 4);

        // Un bâtiment du même code ne remplit pas un prérequis de technologie
        self::assertSame([$espionage], new PrerequisiteRules()->missing([$espionage], ['espionage' => 10], []));
    }
}
