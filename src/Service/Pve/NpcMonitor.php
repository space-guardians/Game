<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\Battle;
use App\Entity\Empire;
use App\Entity\EmpireScore;
use App\Entity\Planet;
use App\Enum\Account\EmpireKind;
use App\Enum\Combat\BattleStatus;
use App\Enum\Combat\CombatSide;
use App\Enum\Pve\NpcCategory;
use App\Model\Pve\NpcFile;
use App\Repository\FleetRepository;
use App\Service\Combat\IncomingAttacks;
use App\Service\Economy\PlanetResources;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi des civilisations non-joueuses (§4.14 : les boss sont surtout monitorés ; §5.6.1 « IA ») : planètes et
 * ressources, flotte courante, statut (attaques en cours, prochain recalcul de flotte), raids lancés et subis.
 */
final readonly class NpcMonitor
{
    public const int HISTORY = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private FleetRepository $fleets,
        private IncomingAttacks $incoming,
        private PlanetResources $resources,
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
            array_map(fn(Planet $planet): array => ['planet' => $planet, 'resources' => $this->resources->snapshot($planet)->amounts], $this->planetsOf($npc)),
            $this->raidsBy($npc),
        );
    }

    /**
     * Vue d'ensemble de toutes les civilisations (§5.6.1 « IA »), par nom.
     *
     * @return list<NpcFile>
     */
    public function all(): array
    {
        /** @var list<Empire> $npcs */
        $npcs = $this->entityManager->getRepository(Empire::class)->findBy(['kind' => EmpireKind::Npc], ['name' => 'ASC']);

        return array_map($this->of(...), $npcs);
    }

    /** @return list<Planet> */
    private function planetsOf(Empire $npc): array
    {
        /** @var list<Planet> */
        return $this->entityManager->getRepository(Planet::class)->findBy(['owner' => $npc], ['id' => 'ASC']);
    }

    /** @return list<Battle> batailles lancées par la civilisation (raids), résolues ou à venir */
    private function raidsBy(Empire $npc): array
    {
        /** @var list<Battle> */
        return $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Battle::class, 'b')
            ->join('b.participants', 'p')
            ->where('p.empire = :npc')
            ->andWhere('p.side = :attacker')
            ->setParameter('npc', $npc)
            ->setParameter('attacker', CombatSide::Attacker)
            ->orderBy('b.resolvesAt', 'DESC')
            ->setMaxResults(self::HISTORY)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Battle> */
    private function battlesOn(Empire $npc): array
    {
        $planetIds = array_map(static fn(Planet $planet): int => (int) $planet->getId(), $this->planetsOf($npc));
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
