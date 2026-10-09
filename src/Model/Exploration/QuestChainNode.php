<?php

declare(strict_types=1);

namespace App\Model\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;

/**
 * Nœud de l'arbre de chaînage d'une quête (§4.6.4) : la quête, puis pour chaque issue la quête qu'elle déclenche.
 * Une quête déjà rencontrée plus haut (boucle) ou trop profonde n'est pas redéveloppée.
 */
final readonly class QuestChainNode
{
    /**
     * @param list<array{outcome: QuestOutcome, next: self|null}> $branches
     */
    public function __construct(
        public QuestTemplate $template,
        public array $branches,
        /** Quête déjà présente plus haut dans la branche : la chaîne boucle */
        public bool $loop = false,
        /** Profondeur maximale atteinte : la suite n'est pas développée */
        public bool $truncated = false,
    ) {}
}
