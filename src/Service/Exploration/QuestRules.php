<?php

declare(strict_types=1);

namespace App\Service\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Model\Economy\Resources;
use App\Model\Exploration\ExplorationContext;
use Random\Randomizer;

/**
 * Règles des quêtes d'exploration (§4.6.4), sans état ni base : conditions de déclenchement, tirage de l'événement
 * qui apparaît, tirage pondéré d'une issue, et effets d'une issue (vaisseaux perdus, cargaison).
 */
final readonly class QuestRules
{
    /**
     * Conditions non remplies par la flotte, en clair ; vide si la quête peut se déclencher.
     *
     * @return list<string>
     */
    public function unmetConditions(QuestTemplate $template, ExplorationContext $context): array
    {
        $unmet = [];
        $technology = $template->getRequiredTechnology();
        if (null !== $technology) {
            $level = max(1, $template->getRequiredTechnologyLevel());
            if (($context->technologyLevels[$technology->getCode()] ?? 0) < $level) {
                $unmet[] = \sprintf('%s niveau %d', $technology->getName(), $level);
            }
        }
        $shipType = $template->getRequiredShipType();
        if (null !== $shipType) {
            $count = max(1, $template->getRequiredShipCount());
            if (($context->ships[$shipType->getCode()] ?? 0) < $count) {
                $unmet[] = \sprintf('%d × %s dans la flotte', $count, $shipType->getName());
            }
        }
        $cargo = $template->requiredCargo();
        if (!$context->cargo->covers($cargo)) {
            $missing = [];
            foreach (['metal' => 'métal', 'crystal' => 'cristal', 'deuterium' => 'deutérium'] as $key => $label) {
                if ($cargo->{$key} > 0) {
                    $missing[] = \sprintf('%d de %s', $cargo->{$key}, $label);
                }
            }
            $unmet[] = 'en cargaison : ' . implode(', ', $missing);
        }

        return $unmet;
    }

    public function isEligible(QuestTemplate $template, ExplorationContext $context): bool
    {
        return [] === $this->unmetConditions($template, $context);
    }

    /**
     * Événement qui apparaît à l'arrivée d'une exploration : un tirage de 0 à 99 parcourt les probabilités cumulées
     * des quêtes éligibles, dans l'ordre donné ; au-delà de leur somme, rien ne se passe. Si elle dépasse 100 %, les
     * dernières quêtes sont rognées.
     *
     * @param list<QuestTemplate> $eligible
     */
    public function drawQuest(array $eligible, Randomizer $randomizer): ?QuestTemplate
    {
        if ([] === $eligible) {
            return null;
        }
        $roll = $randomizer->getInt(0, 99);
        $cumulated = 0;
        foreach ($eligible as $template) {
            $cumulated += $template->getChance();
            if ($roll < $cumulated) {
                return $template;
            }
        }

        return null;
    }

    /** Issue tirée au sort selon les poids ; null si aucune n'a de poids positif */
    public function drawOutcome(QuestTemplate $template, Randomizer $randomizer): ?QuestOutcome
    {
        $total = 0;
        foreach ($template->getOutcomes() as $outcome) {
            $total += max(0, $outcome->getWeight());
        }
        if ($total <= 0) {
            return null;
        }
        $roll = $randomizer->getInt(1, $total);
        foreach ($template->getOutcomes() as $outcome) {
            $roll -= max(0, $outcome->getWeight());
            if ($roll <= 0) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * Vaisseaux perdus : la part de l'issue sur chaque type, arrondie à l'unité inférieure.
     *
     * @param array<string, int> $ships par code de type
     *
     * @return array<string, int> pertes non nulles, par code de type
     */
    public function shipLosses(array $ships, int $percent): array
    {
        $losses = [];
        foreach ($ships as $code => $count) {
            $lost = intdiv($count * max(0, min(100, $percent)), 100);
            if ($lost > 0) {
                $losses[$code] = $lost;
            }
        }

        return $losses;
    }

    /**
     * Cargaison après l'issue : les pertes d'abord (sans descendre sous zéro), puis — si les soutes ont rétréci avec
     * les vaisseaux perdus — une réduction proportionnelle à la capacité restante, enfin les gains dans la limite du
     * cargo libre (métal, puis cristal, puis deutérium).
     */
    public function cargoAfter(Resources $cargo, int $capacity, QuestOutcome $outcome): Resources
    {
        $metal = max(0.0, $cargo->metal + min(0, $outcome->getMetal()));
        $crystal = max(0.0, $cargo->crystal + min(0, $outcome->getCrystal()));
        $deuterium = max(0.0, $cargo->deuterium + min(0, $outcome->getDeuterium()));

        $capacity = max(0, $capacity);
        $total = $metal + $crystal + $deuterium;
        if ($total > $capacity) {
            $ratio = $total > 0 ? $capacity / $total : 0.0;
            [$metal, $crystal, $deuterium] = [floor($metal * $ratio), floor($crystal * $ratio), floor($deuterium * $ratio)];
        }

        $free = max(0.0, $capacity - ($metal + $crystal + $deuterium));
        $gain = static function (int $delta) use (&$free): float {
            $gained = min((float) max(0, $delta), $free);
            $free -= $gained;

            return $gained;
        };

        return new Resources($metal + $gain($outcome->getMetal()), $crystal + $gain($outcome->getCrystal()), $deuterium + $gain($outcome->getDeuterium()));
    }
}
