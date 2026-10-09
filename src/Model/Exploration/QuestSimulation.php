<?php

declare(strict_types=1);

namespace App\Model\Exploration;

/** Aperçu d'une quête sur une flotte de test (§5.6.1) : déclenchement, puis effet de chaque issue */
final readonly class QuestSimulation
{
    /**
     * @param list<string>            $unmet    conditions de déclenchement non remplies
     * @param list<OutcomeSimulation> $outcomes
     */
    public function __construct(
        public array $unmet,
        public array $outcomes,
    ) {}

    public function triggers(): bool
    {
        return [] === $this->unmet;
    }
}
