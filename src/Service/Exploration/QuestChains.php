<?php

declare(strict_types=1);

namespace App\Service\Exploration;

use App\Entity\QuestTemplate;
use App\Model\Exploration\QuestChainNode;

/**
 * Chaînage des quêtes (§4.6.4), pour l'éditeur du panneau : arbre des quêtes qui suivent une quête, issue par issue,
 * avec les boucles repérées. Profondeur bornée comme en jeu (Explorations::MAX_CHAIN).
 */
final readonly class QuestChains
{
    public function tree(QuestTemplate $template): QuestChainNode
    {
        return $this->node($template, [], 0);
    }

    /** @param list<QuestTemplate> $ancestors */
    private function node(QuestTemplate $template, array $ancestors, int $depth): QuestChainNode
    {
        if (\in_array($template, $ancestors, true)) {
            return new QuestChainNode($template, [], loop: true);
        }
        if ($depth >= Explorations::MAX_CHAIN) {
            return new QuestChainNode($template, [], truncated: true);
        }

        $branches = [];
        foreach ($template->getOutcomes() as $outcome) {
            $next = $outcome->getNextQuest();
            $branches[] = [
                'outcome' => $outcome,
                'next' => null === $next ? null : $this->node($next, [...$ancestors, $template], $depth + 1),
            ];
        }

        return new QuestChainNode($template, $branches);
    }
}
