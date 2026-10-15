<?php

declare(strict_types=1);

namespace App\Model\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Model\Combat\IncomingAttack;
use App\Model\Economy\Resources;

/**
 * Fiche de suivi d'une civilisation non-joueuse dans le panneau (§4.14, §5.6.1) : planètes et ressources, flottes,
 * points militaires, prochain recalcul (boss), attaques en cours, raids lancés et batailles subies sur ses planètes.
 */
final readonly class NpcFile
{
    /**
     * @param list<Fleet>          $fleets
     * @param list<IncomingAttack> $incoming
     * @param list<Battle>         $battles  batailles résolues sur ses planètes, les plus récentes d'abord
     * @param list<array{planet: Planet, resources: Resources}> $planets ses planètes, stock à l'instant
     * @param list<Battle>         $raids    batailles qu'elle a lancées, les plus récentes d'abord
     */
    public function __construct(
        public Empire $empire,
        public array $fleets,
        public int $militaryPoints,
        public ?\DateTimeImmutable $nextRecalculation,
        public array $incoming,
        public array $battles,
        public array $planets = [],
        public array $raids = [],
    ) {}

    /** Ressources de toutes ses planètes, à l'instant */
    public function resources(): Resources
    {
        return array_reduce($this->planets, static fn(Resources $total, array $planet): Resources => $total->plus($planet['resources']), Resources::zero());
    }

    public function shipCount(): int
    {
        return array_sum(array_map(static fn(Fleet $fleet): int => $fleet->shipCount(), $this->fleets));
    }
}
