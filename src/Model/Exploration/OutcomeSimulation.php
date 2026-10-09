<?php

declare(strict_types=1);

namespace App\Model\Exploration;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use App\Model\Economy\Resources;

/** Ce que produirait une issue sur la flotte de test (aperçu du panneau, §5.6.1) */
final readonly class OutcomeSimulation
{
    /**
     * @param float|null         $probability chance d'être tirée (quête automatique) ; null si le joueur choisit
     * @param array<string, int> $losses      vaisseaux perdus, par code de type
     * @param list<string>       $nextUnmet   conditions de la quête suivante que la flotte ne remplirait plus
     */
    public function __construct(
        public QuestOutcome $outcome,
        public ?float $probability,
        public array $losses,
        public Resources $cargo,
        public bool $fleetLost,
        public ?QuestTemplate $next,
        public array $nextUnmet,
    ) {}

    /** La quête suivante s'enchaînerait-elle réellement en jeu ? */
    public function chains(): bool
    {
        return null !== $this->next && $this->next->isPublished() && !$this->fleetLost && [] === $this->nextUnmet;
    }
}
