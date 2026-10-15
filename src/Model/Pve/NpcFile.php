<?php

declare(strict_types=1);

namespace App\Model\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\Fleet;
use App\Model\Combat\IncomingAttack;

/**
 * Fiche de suivi d'une civilisation non-joueuse dans le panneau (§4.14, §5.6.1) : flottes, points militaires, prochain
 * recalcul (boss), attaques en cours et batailles subies sur ses planètes.
 */
final readonly class NpcFile
{
    /**
     * @param list<Fleet>          $fleets
     * @param list<IncomingAttack> $incoming
     * @param list<Battle>         $battles  batailles résolues sur ses planètes, les plus récentes d'abord
     */
    public function __construct(
        public Empire $empire,
        public array $fleets,
        public int $militaryPoints,
        public ?\DateTimeImmutable $nextRecalculation,
        public array $incoming,
        public array $battles,
    ) {}

    public function shipCount(): int
    {
        return array_sum(array_map(static fn(Fleet $fleet): int => $fleet->shipCount(), $this->fleets));
    }
}
