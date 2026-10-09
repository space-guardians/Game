<?php

declare(strict_types=1);

namespace App\Service\Exploration;

use App\Entity\QuestTemplate;
use App\Model\Economy\Resources;
use App\Model\Exploration\ExplorationContext;
use App\Model\Exploration\OutcomeSimulation;
use App\Model\Exploration\QuestSimulation;

/**
 * Aperçu et test d'une quête avant publication (§5.6.1), sans base ni hasard : sur une flotte de test (technologies,
 * vaisseaux, cargaison), la quête se déclencherait-elle, avec quelle chance chaque issue serait tirée, ce qu'elle
 * ferait à la flotte, et si la quête suivante s'enchaînerait. Mêmes règles qu'en jeu (QuestRules).
 */
final readonly class QuestPreview
{
    public function __construct(
        private QuestRules $rules,
    ) {}

    /**
     * @param array<string, int> $technologyLevels par code de technologie
     * @param array<string, int> $ships            par code de type
     * @param array<string, int> $cargoPerShip     capacité de cargo d'un vaisseau, par code de type
     */
    public function simulate(QuestTemplate $template, array $technologyLevels, array $ships, Resources $cargo, array $cargoPerShip): QuestSimulation
    {
        $capacity = static function (array $fleet) use ($cargoPerShip): int {
            $total = 0;
            foreach ($fleet as $code => $count) {
                $total += ($cargoPerShip[$code] ?? 0) * $count;
            }

            return $total;
        };
        $context = new ExplorationContext($technologyLevels, $ships, $cargo, $capacity($ships));

        $weights = 0;
        foreach ($template->getOutcomes() as $outcome) {
            $weights += max(0, $outcome->getWeight());
        }

        $outcomes = [];
        foreach ($template->getOutcomes() as $outcome) {
            $losses = $this->rules->shipLosses($ships, $outcome->getShipLossPercent());
            $remaining = [];
            foreach ($ships as $code => $count) {
                if ($count - ($losses[$code] ?? 0) > 0) {
                    $remaining[$code] = $count - ($losses[$code] ?? 0);
                }
            }
            $cargoAfter = $this->rules->cargoAfter($cargo, $capacity($remaining), $outcome);
            $next = $outcome->getNextQuest();
            $nextUnmet = null === $next ? [] : $this->rules->unmetConditions($next, new ExplorationContext($technologyLevels, $remaining, $cargoAfter, $capacity($remaining)));

            $outcomes[] = new OutcomeSimulation(
                $outcome,
                $template->isPlayerChoice() ? null : ($weights > 0 ? max(0, $outcome->getWeight()) / $weights : 0.0),
                $losses,
                $cargoAfter,
                [] === $remaining,
                $next,
                $nextUnmet,
            );
        }

        return new QuestSimulation($this->rules->unmetConditions($template, $context), $outcomes);
    }

    /**
     * Flotte de test par défaut : la plus petite qui remplit les conditions de la quête.
     *
     * @return array{technologies: array<string, int>, ships: array<string, int>, cargo: Resources}
     */
    public function minimalFleet(QuestTemplate $template, string $defaultShip): array
    {
        $technology = $template->getRequiredTechnology();
        $shipType = $template->getRequiredShipType();

        return [
            'technologies' => null === $technology ? [] : [$technology->getCode() => max(1, $template->getRequiredTechnologyLevel())],
            'ships' => null === $shipType ? [$defaultShip => 1] : [$shipType->getCode() => max(1, $template->getRequiredShipCount())],
            'cargo' => $template->requiredCargo(),
        ];
    }
}
