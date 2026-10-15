<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\Planet;
use App\Enum\Combat\BattleStatus;
use App\Enum\Pve\NpcCategory;
use App\Model\Pve\NpcFile;
use App\Repository\FleetRepository;
use App\Service\Combat\IncomingAttacks;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi d'une civilisation non-joueuse (§4.14 : les boss sont surtout monitorés) : flotte courante, statut (attaques en
 * cours, prochain recalcul de flotte) et historique des raids subis.
 */
final readonly class NpcMonitor
{
    public const int HISTORY = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetRepository $fleets,
        private IncomingAttacks $incoming,
    ) {}

    public function of(Empire $npc): NpcFile
    {
        $profile = $npc->getNpcProfile();
        $recalculated = $npc->getNpcFleetRecalculatedAt();
        $next = NpcCategory::Boss === $profile?->getCategory() && null !== $recalculated
            ? $recalculated->modify(\sprintf('+%d hours', $profile->getFleetRecalcHours()))
            : null;
        $score = $this->entityManager->getRepository(EmpireScore::class)->findOneBy(['empire' => $npc]);

        return new NpcFile(
            $npc,
            $this->fleets->findBy(['empire' => $npc], ['id' => 'ASC']),
            $score?->getMilitary() ?? 0,
            $next,
            $this->incoming->of($npc),
            $this->battlesOn($npc),
        );
    }

    /** @return list<Battle> */
    private function battlesOn(Empire $npc): array
    {
        $planetIds = array_map(
            static fn(Planet $planet): int => (int) $planet->getId(),
            $this->entityManager->getRepository(Planet::class)->findBy(['owner' => $npc]),
        );
        if ([] === $planetIds) {
            return [];
        }

        /** @var list<Battle> */
        return $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Battle::class, 'b')
            ->where('b.location.planetId IN (:planets)')
            ->andWhere('b.status = :resolved')
            ->setParameter('planets', $planetIds)
            ->setParameter('resolved', BattleStatus::Resolved)
            ->orderBy('b.resolvedAt', 'DESC')
            ->setMaxResults(self::HISTORY)
            ->getQuery()
            ->getResult();
    }
}
